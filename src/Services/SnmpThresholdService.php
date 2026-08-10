<?php
/**
 * ColdAisle — custom SNMP metric thresholds (beyond power digests / env sensors).
 *
 * Rules live in snmp_thresholds; per-entity state in snmp_threshold_state.
 * Evaluated after SNMP poll with the live metrics map (and common poll columns).
 * Delivery via AlertService::emit (category snmp).
 */
declare(strict_types=1);

class SnmpThresholdService
{
    public const SETTING_ENABLED = 'snmp_thresholds_enabled';

    /** @return array{enabled:bool} */
    public static function settings(): array
    {
        return [
            'enabled' => SettingsService::get(self::SETTING_ENABLED, '0') === '1',
        ];
    }

    /** @param array<string,mixed> $post */
    public static function saveSettingsFromPost(array $post): void
    {
        SettingsService::set(
            self::SETTING_ENABLED,
            !empty($post['snmp_thresholds_enabled']) ? '1' : '0',
            'alerts'
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listThresholds(): array
    {
        try {
            return Database::fetchAll(
                'SELECT * FROM snmp_thresholds ORDER BY is_active DESC, name'
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function saveThreshold(array $data, ?int $id = null): int
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            $name = 'SNMP threshold';
        }
        $entityType = strtolower(trim((string)($data['entity_type'] ?? 'device')));
        if (!in_array($entityType, ['device', 'pdu', 'cooling'], true)) {
            $entityType = 'device';
        }
        $metricKey = trim((string)($data['metric_key'] ?? ''));
        if ($metricKey === '') {
            throw new RuntimeException('Metric key is required (template field name, e.g. watts or temperature.1).');
        }
        $entityId = !empty($data['entity_id']) ? (int)$data['entity_id'] : null;
        if ($entityId !== null && $entityId < 1) {
            $entityId = null;
        }
        $scale = (float)($data['scale_divisor'] ?? 1);
        if ($scale <= 0) {
            $scale = 1.0;
        }
        $cd = max(5, min(10080, (int)($data['cooldown_min'] ?? 60)));

        $payload = [
            'name' => mb_substr($name, 0, 150),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metric_key' => mb_substr($metricKey, 0, 100),
            'oid' => (($o = trim((string)($data['oid'] ?? ''))) !== '') ? mb_substr($o, 0, 255) : null,
            'warn_low' => self::nullableFloat($data['warn_low'] ?? null),
            'warn_high' => self::nullableFloat($data['warn_high'] ?? null),
            'crit_low' => self::nullableFloat($data['crit_low'] ?? null),
            'crit_high' => self::nullableFloat($data['crit_high'] ?? null),
            'unit' => (($u = trim((string)($data['unit'] ?? ''))) !== '') ? mb_substr($u, 0, 20) : null,
            'scale_divisor' => $scale,
            'cooldown_min' => $cd,
            'is_active' => !isset($data['is_active']) || !empty($data['is_active']) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($payload['warn_high'] === null && $payload['crit_high'] === null
            && $payload['warn_low'] === null && $payload['crit_low'] === null
        ) {
            throw new RuntimeException('Set at least one warn or crit bound (high or low).');
        }

        if ($id && $id > 0) {
            Database::update('snmp_thresholds', $payload, 'threshold_id = :id', [':id' => $id]);
            return $id;
        }
        $payload['created_at'] = date('Y-m-d H:i:s');
        return (int)Database::insert('snmp_thresholds', $payload);
    }

    public static function deleteThreshold(int $id): void
    {
        if ($id < 1) {
            return;
        }
        try {
            Database::query('DELETE FROM snmp_threshold_state WHERE threshold_id = ?', [$id]);
            Database::query('DELETE FROM snmp_thresholds WHERE threshold_id = ?', [$id]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    /**
     * Evaluate all active rules for an entity using a metrics map from poll.
     *
     * @param 'device'|'pdu'|'cooling' $entityType
     * @param array<string,mixed> $metrics  key => numeric|array{numeric:?float}|scalar
     * @return array{checked:int,alerted:int}
     */
    public static function evaluateEntity(string $entityType, int $entityId, string $label, array $metrics): array
    {
        $cfg = self::settings();
        if (!$cfg['enabled'] || $entityId < 1) {
            return ['checked' => 0, 'alerted' => 0];
        }
        $entityType = strtolower($entityType);
        if (!in_array($entityType, ['device', 'pdu', 'cooling'], true)) {
            return ['checked' => 0, 'alerted' => 0];
        }

        try {
            $rules = Database::fetchAll(
                'SELECT * FROM snmp_thresholds
                 WHERE is_active = 1 AND entity_type = ?
                   AND (entity_id IS NULL OR entity_id = ?)',
                [$entityType, $entityId]
            );
        } catch (Throwable $e) {
            return ['checked' => 0, 'alerted' => 0];
        }
        if (!$rules) {
            return ['checked' => 0, 'alerted' => 0];
        }

        $checked = 0;
        $alerted = 0;
        foreach ($rules as $rule) {
            $checked++;
            if (self::evaluateRule($rule, $entityType, $entityId, $label, $metrics)) {
                $alerted++;
            }
        }
        return ['checked' => $checked, 'alerted' => $alerted];
    }

    /**
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $metrics
     */
    private static function evaluateRule(
        array $rule,
        string $entityType,
        int $entityId,
        string $label,
        array $metrics
    ): bool {
        $tid = (int)($rule['threshold_id'] ?? 0);
        $key = (string)($rule['metric_key'] ?? '');
        if ($tid < 1 || $key === '') {
            return false;
        }

        $raw = self::extractMetric($metrics, $key);
        if ($raw === null) {
            // Also try common poll column aliases
            $raw = self::extractMetric($metrics, strtolower($key));
        }
        if ($raw === null) {
            return false;
        }
        $scale = (float)($rule['scale_divisor'] ?? 1);
        if ($scale <= 0) {
            $scale = 1.0;
        }
        $value = $raw / $scale;

        $level = self::levelForValue(
            $value,
            self::nullableFloat($rule['warn_low'] ?? null),
            self::nullableFloat($rule['warn_high'] ?? null),
            self::nullableFloat($rule['crit_low'] ?? null),
            self::nullableFloat($rule['crit_high'] ?? null)
        );

        $state = self::loadState($tid, $entityType, $entityId);
        $prev = strtolower((string)($state['last_alert_level'] ?? 'ok'));
        $prevAt = (string)($state['last_alert_at'] ?? '');
        $cooldownSec = max(5, (int)($rule['cooldown_min'] ?? 60)) * 60;

        if ($level === 'ok') {
            if (in_array($prev, ['warn', 'crit'], true)) {
                self::saveState($tid, $entityType, $entityId, 'ok', $value, false);
            } else {
                self::saveState($tid, $entityType, $entityId, 'ok', $value, false);
            }
            return false;
        }

        // Cooldown: same or lesser severity recently
        if (in_array($prev, ['warn', 'crit'], true) && $prevAt !== '') {
            $ts = strtotime($prevAt);
            if ($ts !== false && (time() - $ts) < $cooldownSec) {
                if (!($prev === 'warn' && $level === 'crit')) {
                    self::saveState($tid, $entityType, $entityId, $prev, $value, false);
                    return false;
                }
            }
        }

        $unit = trim((string)($rule['unit'] ?? ''));
        $ruleName = (string)($rule['name'] ?? 'SNMP threshold');
        $title = sprintf(
            'SNMP %s: %s — %s',
            strtoupper($level),
            $ruleName,
            $label
        );
        $disp = rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
        $bounds = self::formatBounds($rule);
        $message = "ColdAisle SNMP threshold alert\n\n"
            . "Rule: {$ruleName}\n"
            . "Entity: {$entityType} #{$entityId} — {$label}\n"
            . "Metric: {$key}\n"
            . "Value: {$disp}" . ($unit !== '' ? " {$unit}" : '') . "\n"
            . "Status: " . strtoupper($level) . "\n"
            . "Bounds: {$bounds}\n"
            . 'Time: ' . date('c') . "\n";

        $departmentId = null;
        if ($entityType === 'device') {
            try {
                $departmentId = (int)(Database::fetchValue(
                    'SELECT department_id FROM devices WHERE device_id = ?',
                    [$entityId]
                ) ?: 0) ?: null;
            } catch (Throwable $e) {
                $departmentId = null;
            }
        }

        if (class_exists('AlertService')) {
            try {
                AlertService::emit([
                    'category' => AlertService::CAT_SNMP,
                    'severity' => $level === 'crit' ? AlertService::SEV_CRITICAL : AlertService::SEV_WARNING,
                    'title' => $title,
                    'message' => $message,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'department_id' => $departmentId,
                ]);
            } catch (Throwable $e) {
                App::log('SnmpThreshold AlertService: ' . $e->getMessage(), 'error');
                return false;
            }
        } else {
            try {
                Database::insert('notifications', [
                    'user_id' => null,
                    'title' => mb_substr($title, 0, 200),
                    'message' => mb_substr($message, 0, 4000),
                    'category' => 'snmp',
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'is_read' => 0,
                ]);
            } catch (Throwable $e) {
                return false;
            }
        }

        self::saveState($tid, $entityType, $entityId, $level, $value, true);
        App::log("SnmpThreshold alert tid={$tid} {$entityType}#{$entityId} level={$level} value={$disp}", 'info');
        return true;
    }

    /**
     * Build a flat metrics map from a devices/pdus row poll columns (fallback).
     * @param array<string,mixed> $row
     * @return array<string,float>
     */
    public static function metricsFromPollRow(string $entityType, array $row): array
    {
        $m = [];
        if ($entityType === 'pdu') {
            if (isset($row['last_poll_watts']) && $row['last_poll_watts'] !== null && $row['last_poll_watts'] !== '') {
                $m['watts'] = (float)$row['last_poll_watts'];
            }
            if (isset($row['last_poll_amps']) && $row['last_poll_amps'] !== null && $row['last_poll_amps'] !== '') {
                $m['amps'] = (float)$row['last_poll_amps'];
            }
            if (!empty($row['last_poll_phases'])) {
                $phases = is_string($row['last_poll_phases'])
                    ? (json_decode((string)$row['last_poll_phases'], true) ?: [])
                    : (array)$row['last_poll_phases'];
                foreach ($phases as $i => $ph) {
                    if (!is_array($ph)) {
                        continue;
                    }
                    $n = (int)$i + 1;
                    if (isset($ph['amps'])) {
                        $m['phase' . $n . '_amps'] = (float)$ph['amps'];
                    }
                    if (isset($ph['watts'])) {
                        $m['phase' . $n . '_watts'] = (float)$ph['watts'];
                    }
                    if (isset($ph['util_pct'])) {
                        $m['phase' . $n . '_util'] = (float)$ph['util_pct'];
                    }
                }
            }
        } elseif ($entityType === 'device') {
            if (isset($row['snmp_last_poll_watts']) && $row['snmp_last_poll_watts'] !== null) {
                $m['watts'] = (float)$row['snmp_last_poll_watts'];
            }
            if (isset($row['snmp_last_poll_amps']) && $row['snmp_last_poll_amps'] !== null) {
                $m['amps'] = (float)$row['snmp_last_poll_amps'];
            }
        } elseif ($entityType === 'cooling' && !empty($row['last_poll_json'])) {
            $snap = json_decode((string)$row['last_poll_json'], true);
            if (is_array($snap)) {
                $inner = $snap['metrics'] ?? $snap;
                if (is_array($inner)) {
                    foreach ($inner as $k => $v) {
                        $num = self::extractMetric([$k => $v], (string)$k);
                        if ($num !== null) {
                            $m[(string)$k] = $num;
                        }
                    }
                }
            }
        }
        return $m;
    }

    /**
     * Normalize poll metrics array (SnmpPoller style) to key => float.
     * @param array<string,mixed> $metrics
     * @return array<string,float>
     */
    public static function flattenPollMetrics(array $metrics): array
    {
        $out = [];
        foreach ($metrics as $k => $v) {
            $num = self::extractMetric([$k => $v], (string)$k);
            if ($num !== null) {
                $out[(string)$k] = $num;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $metrics */
    private static function extractMetric(array $metrics, string $key): ?float
    {
        if (!array_key_exists($key, $metrics)) {
            return null;
        }
        $v = $metrics[$key];
        if (is_array($v)) {
            if (isset($v['numeric']) && $v['numeric'] !== null && $v['numeric'] !== '' && is_numeric($v['numeric'])) {
                return (float)$v['numeric'];
            }
            if (isset($v['value']) && is_numeric($v['value'])) {
                return (float)$v['value'];
            }
            if (isset($v['raw']) && is_numeric($v['raw'])) {
                return (float)$v['raw'];
            }
            return null;
        }
        if (is_numeric($v)) {
            return (float)$v;
        }
        return null;
    }

    private static function levelForValue(
        float $value,
        ?float $warnLow,
        ?float $warnHigh,
        ?float $critLow,
        ?float $critHigh
    ): string {
        if ($critHigh !== null && $value >= $critHigh) {
            return 'crit';
        }
        if ($critLow !== null && $value <= $critLow) {
            return 'crit';
        }
        if ($warnHigh !== null && $value >= $warnHigh) {
            return 'warn';
        }
        if ($warnLow !== null && $value <= $warnLow) {
            return 'warn';
        }
        return 'ok';
    }

    /** @param array<string,mixed> $rule */
    private static function formatBounds(array $rule): string
    {
        $parts = [];
        foreach (['crit_low' => 'crit≤', 'warn_low' => 'warn≤', 'warn_high' => 'warn≥', 'crit_high' => 'crit≥'] as $k => $lab) {
            $v = self::nullableFloat($rule[$k] ?? null);
            if ($v !== null) {
                $parts[] = $lab . rtrim(rtrim(sprintf('%.4F', $v), '0'), '.');
            }
        }
        return $parts ? implode(' · ', $parts) : '—';
    }

    /** @return array<string,mixed> */
    private static function loadState(int $tid, string $entityType, int $entityId): array
    {
        try {
            $row = Database::fetchOne(
                'SELECT * FROM snmp_threshold_state
                 WHERE threshold_id = ? AND entity_type = ? AND entity_id = ?',
                [$tid, $entityType, $entityId]
            );
            return $row ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function saveState(
        int $tid,
        string $entityType,
        int $entityId,
        string $level,
        float $value,
        bool $alerted
    ): void {
        $now = date('Y-m-d H:i:s');
        try {
            $existing = self::loadState($tid, $entityType, $entityId);
            $payload = [
                'last_alert_level' => $level,
                'last_value' => $value,
                'updated_at' => $now,
            ];
            if ($alerted || $level === 'ok') {
                $payload['last_alert_at'] = $now;
            }
            if ($existing) {
                Database::update(
                    'snmp_threshold_state',
                    $payload,
                    'threshold_id = :tid AND entity_type = :et AND entity_id = :eid',
                    [':tid' => $tid, ':et' => $entityType, ':eid' => $entityId]
                );
            } else {
                Database::insert('snmp_threshold_state', array_merge($payload, [
                    'threshold_id' => $tid,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'last_alert_at' => $now,
                    'created_at' => $now,
                ]));
            }
        } catch (Throwable $e) {
            App::log('SnmpThreshold state: ' . $e->getMessage(), 'warning');
        }
    }

    private static function nullableFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_numeric($v)) {
            return null;
        }
        return (float)$v;
    }
}
