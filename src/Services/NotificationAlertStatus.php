<?php
/**
 * Resolve whether an in-app notification is still an active condition or has cleared.
 *
 * Problem alerts (ICMP down, power, env warn/crit) stay in history but show a
 * green check when the underlying condition has recovered. Recovery events
 * themselves are also treated as cleared/success.
 */
declare(strict_types=1);

class NotificationAlertStatus
{
    public const STATE_ACTIVE = 'active';
    public const STATE_CLEARED = 'cleared';
    public const STATE_INFO = 'info';

    /**
     * Enrich notification rows with is_cleared, cleared_at, alert_state, alert_state_label.
     * Lazily persists is_cleared=1 when live checks show recovery.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function enrich(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $icmpDev = self::loadIcmpMap('device');
        $icmpPdu = self::loadIcmpMap('pdu');
        $envLevels = self::loadEnvLevels();
        $activePowerPdus = self::loadActivePowerPduIds();
        $anyPowerActive = $activePowerPdus !== [];

        $toPersist = [];
        $now = date('Y-m-d H:i:s');

        foreach ($rows as &$r) {
            $storedCleared = !empty($r['is_cleared']);
            $title = (string)($r['title'] ?? '');
            $cat = strtolower((string)($r['category'] ?? 'info'));
            $etype = strtolower(trim((string)($r['entity_type'] ?? '')));
            $eid = isset($r['entity_id']) && $r['entity_id'] !== null && $r['entity_id'] !== ''
                ? (int)$r['entity_id']
                : 0;

            $state = self::STATE_INFO;
            $cleared = $storedCleared;

            // Explicit recovery / success notifications
            if (
                stripos($title, 'recovered') !== false
                || stripos($title, 'recovery') !== false
                || in_array($cat, ['success', 'ok'], true)
            ) {
                $state = self::STATE_CLEARED;
                $cleared = true;
            } elseif (self::isProblemCategory($cat, $title)) {
                $live = self::liveCleared(
                    $etype,
                    $eid,
                    $title,
                    $cat,
                    $icmpDev,
                    $icmpPdu,
                    $envLevels,
                    $activePowerPdus,
                    $anyPowerActive
                );
                if ($live === true || $storedCleared) {
                    $state = self::STATE_CLEARED;
                    $cleared = true;
                } elseif ($live === false) {
                    $state = self::STATE_ACTIVE;
                    $cleared = false;
                } else {
                    // Unknown entity — treat stored flag or assume active for problem cats
                    $state = $storedCleared ? self::STATE_CLEARED : self::STATE_ACTIVE;
                    $cleared = $storedCleared;
                }
            }

            $r['is_cleared'] = $cleared ? 1 : 0;
            $r['alert_state'] = $state;
            $r['alert_state_label'] = match ($state) {
                self::STATE_CLEARED => 'Cleared',
                self::STATE_ACTIVE => 'Active',
                default => '',
            };
            if ($cleared && empty($r['cleared_at'])) {
                $r['cleared_at'] = $r['cleared_at'] ?? $now;
            }

            $nid = (int)($r['notification_id'] ?? $r['id'] ?? 0);
            if ($nid > 0 && $cleared && !$storedCleared) {
                $toPersist[] = $nid;
            }
        }
        unset($r);

        if ($toPersist !== []) {
            self::persistClearedIds($toPersist);
        }

        return $rows;
    }

    /**
     * Mark prior problem notifications for an entity as cleared (e.g. ICMP recovery).
     */
    public static function markEntityCleared(string $entityType, int $entityId): void
    {
        $entityType = strtolower(trim($entityType));
        if ($entityType === '' || $entityId < 1) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        try {
            Database::query(
                "UPDATE notifications
                 SET is_cleared = 1, cleared_at = COALESCE(cleared_at, ?)
                 WHERE entity_type = ?
                   AND entity_id = ?
                   AND (is_cleared IS NULL OR is_cleared = 0)
                   AND title NOT LIKE '%recovered%'
                   AND title NOT LIKE '%recovery%'",
                [$now, $entityType, $entityId]
            );
        } catch (Throwable $e) {
            // columns may not exist yet
            try {
                // Fallback without is_cleared if mid-migrate (no-op)
            } catch (Throwable $e2) {
            }
        }
    }

    /** Mark all uncleared power_digest / power category problem notices cleared when no active power alerts remain. */
    public static function markPowerDigestsClearedIfQuiet(): void
    {
        if (self::loadActivePowerPduIds() !== []) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        try {
            Database::query(
                "UPDATE notifications
                 SET is_cleared = 1, cleared_at = COALESCE(cleared_at, ?)
                 WHERE (is_cleared IS NULL OR is_cleared = 0)
                   AND (
                     entity_type = 'power_digest'
                     OR category IN ('power', 'warning', 'critical')
                   )
                   AND (
                     title LIKE 'Power %'
                     OR title LIKE '%Power alert%'
                     OR title LIKE '%Power event%'
                     OR entity_type = 'power_digest'
                   )",
                [$now]
            );
        } catch (Throwable $e) {
        }
    }

    private static function isProblemCategory(string $cat, string $title): bool
    {
        if (in_array($cat, ['warning', 'critical', 'power', 'icmp', 'env', 'snmp', 'error', 'danger'], true)) {
            return true;
        }
        if (stripos($title, 'DOWN') !== false || stripos($title, 'critical') !== false) {
            return true;
        }
        if (stripos($title, 'alert') !== false || stripos($title, 'warn') !== false) {
            return true;
        }
        return false;
    }

    /**
     * @param array<int,string> $icmpDev
     * @param array<int,string> $icmpPdu
     * @param array<int,string> $envLevels
     * @param array<int,true> $activePowerPdus
     * @return bool|null true=cleared, false=active, null=unknown
     */
    private static function liveCleared(
        string $etype,
        int $eid,
        string $title,
        string $cat,
        array $icmpDev,
        array $icmpPdu,
        array $envLevels,
        array $activePowerPdus,
        bool $anyPowerActive
    ): ?bool {
        // ICMP device / pdu
        if ($etype === 'device' && $eid > 0) {
            $st = $icmpDev[$eid] ?? null;
            if ($st === null) {
                return null;
            }
            return $st === 'up' || $st === 'ok' || $st === 'off';
        }
        if ($etype === 'pdu' && $eid > 0) {
            // PDU may have ICMP and/or power alerts
            $icmp = $icmpPdu[$eid] ?? null;
            $powerActive = isset($activePowerPdus[$eid]);
            $icmpBad = $icmp !== null && !in_array($icmp, ['up', 'ok', 'off', 'unknown'], true)
                && in_array($icmp, ['down', 'degraded'], true);
            if ($powerActive || $icmpBad) {
                return false;
            }
            if ($icmp === 'up' || $icmp === 'ok' || !$powerActive) {
                // If no icmp monitor and no power, treat cleared for power-only clears
                if ($icmp === null && !$powerActive) {
                    return true;
                }
                if ($icmp === 'up' || $icmp === 'ok') {
                    return true;
                }
            }
            return $icmp === null ? !$powerActive : true;
        }

        if ($etype === 'env_sensor' && $eid > 0) {
            $lvl = strtolower($envLevels[$eid] ?? 'ok');
            return !in_array($lvl, ['warn', 'warning', 'crit', 'critical', 'stale'], true);
        }

        if ($etype === 'power_digest' || $cat === 'power') {
            return !$anyPowerActive;
        }

        // Title-based ICMP without entity (legacy)
        if (stripos($title, 'ICMP') !== false && stripos($title, 'DOWN') !== false) {
            return null;
        }

        return null;
    }

    /** @return array<int,string> id => icmp status */
    private static function loadIcmpMap(string $kind): array
    {
        $out = [];
        try {
            if ($kind === 'device') {
                $rows = Database::fetchAll(
                    'SELECT device_id AS id, icmp_monitor, icmp_fail_count, icmp_last_ok, icmp_last_at, icmp_last_error
                     FROM devices WHERE is_active = 1 AND icmp_monitor = 1'
                );
            } else {
                $rows = Database::fetchAll(
                    'SELECT pdu_id AS id, icmp_monitor, icmp_fail_count, icmp_last_ok, icmp_last_at, icmp_last_error
                     FROM pdus WHERE is_active = 1 AND icmp_monitor = 1'
                );
            }
        } catch (Throwable $e) {
            return [];
        }
        if (!class_exists('IcmpMonitorService') && is_file(__DIR__ . '/IcmpMonitorService.php')) {
            require_once __DIR__ . '/IcmpMonitorService.php';
        }
        foreach ($rows as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            if (class_exists('IcmpMonitorService')) {
                $st = IcmpMonitorService::statusFromRow($kind, $r);
                $out[$id] = strtolower((string)($st['status'] ?? 'unknown'));
            } else {
                $out[$id] = !empty($r['icmp_last_ok']) ? 'up' : 'unknown';
            }
        }
        return $out;
    }

    /** @return array<int,string> sensor_id => last_alert_level */
    private static function loadEnvLevels(): array
    {
        $out = [];
        try {
            $rows = Database::fetchAll(
                'SELECT sensor_id, last_alert_level FROM env_sensors WHERE is_active = 1'
            );
        } catch (Throwable $e) {
            return [];
        }
        foreach ($rows as $r) {
            $id = (int)($r['sensor_id'] ?? 0);
            if ($id > 0) {
                $out[$id] = strtolower((string)($r['last_alert_level'] ?? 'ok'));
            }
        }
        return $out;
    }

    /** @return array<int,true> */
    private static function loadActivePowerPduIds(): array
    {
        $out = [];
        try {
            $rows = Database::fetchAll(
                'SELECT DISTINCT pdu_id FROM power_alert_state WHERE is_active = 1 AND pdu_id IS NOT NULL'
            );
        } catch (Throwable $e) {
            return [];
        }
        foreach ($rows as $r) {
            $id = (int)($r['pdu_id'] ?? 0);
            if ($id > 0) {
                $out[$id] = true;
            }
        }
        return $out;
    }

    /** @param list<int> $ids */
    private static function persistClearedIds(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        // Batch in chunks for SQL Server
        foreach (array_chunk($ids, 40) as $chunk) {
            $list = implode(',', $chunk);
            try {
                Database::query(
                    "UPDATE notifications
                     SET is_cleared = 1, cleared_at = COALESCE(cleared_at, ?)
                     WHERE notification_id IN ({$list})
                       AND (is_cleared IS NULL OR is_cleared = 0)",
                    [$now]
                );
            } catch (Throwable $e) {
                // ignore
            }
        }
    }
}
