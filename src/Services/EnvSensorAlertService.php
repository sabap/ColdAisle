<?php
/**
 * Env sensor threshold alerts after SNMP poll or manual reading.
 */
declare(strict_types=1);

class EnvSensorAlertService
{
    public const SETTING_ENABLED = 'env_alerts_enabled';
    public const SETTING_EMAIL = 'env_alerts_email';
    public const SETTING_COOLDOWN = 'env_alerts_cooldown_min';
    public const SETTING_RH_WARN = 'env_alerts_rh_warn';
    public const SETTING_RH_CRIT = 'env_alerts_rh_crit';
    public const SETTING_STALE_ENABLED = 'env_alerts_stale_enabled';
    public const SETTING_STALE_MIN = 'env_alerts_stale_min';
    public const SETTING_DIGEST = 'env_alerts_digest';

    /**
     * @return array{
     *   enabled:bool,email:string,cooldown_min:int,rh_warn:float,rh_crit:float,
     *   stale_enabled:bool,stale_min:int,digest:bool
     * }
     */
    public static function settings(): array
    {
        $cd = (int)SettingsService::get(self::SETTING_COOLDOWN, '60');
        if ($cd < 5) {
            $cd = 5;
        }
        if ($cd > 10080) {
            $cd = 10080;
        }
        $email = trim((string)SettingsService::get(self::SETTING_EMAIL, ''));
        if ($email === '') {
            // Fall back to power alerts email if env list empty
            $email = trim((string)SettingsService::get('power_alerts_email', ''));
        }
        $rhW = (float)SettingsService::get(self::SETTING_RH_WARN, '70');
        $rhC = (float)SettingsService::get(self::SETTING_RH_CRIT, '90');
        if ($rhW < 1) {
            $rhW = 70.0;
        }
        if ($rhC < $rhW) {
            $rhC = max($rhW, 90.0);
        }
        $staleMin = (int)SettingsService::get(self::SETTING_STALE_MIN, '60');
        if ($staleMin < 15) {
            $staleMin = 15;
        }
        if ($staleMin > 10080) {
            $staleMin = 10080;
        }
        return [
            'enabled' => SettingsService::get(self::SETTING_ENABLED, '0') === '1',
            'email' => $email,
            'cooldown_min' => $cd,
            'rh_warn' => $rhW,
            'rh_crit' => $rhC,
            'stale_enabled' => SettingsService::get(self::SETTING_STALE_ENABLED, '1') === '1',
            'stale_min' => $staleMin,
            'digest' => SettingsService::get(self::SETTING_DIGEST, '1') === '1',
        ];
    }

    /** @param array<string,mixed> $post */
    public static function saveSettingsFromPost(array $post): void
    {
        SettingsService::set(
            self::SETTING_ENABLED,
            !empty($post['env_alerts_enabled']) ? '1' : '0',
            'env_alerts'
        );
        $emails = self::normalizeEmailList((string)($post['env_alerts_email'] ?? ''));
        SettingsService::set(self::SETTING_EMAIL, implode(', ', $emails), 'env_alerts');
        $cd = max(5, min(10080, (int)($post['env_alerts_cooldown_min'] ?? 60)));
        SettingsService::set(self::SETTING_COOLDOWN, (string)$cd, 'env_alerts');
        $rhW = max(1, min(100, (float)($post['env_alerts_rh_warn'] ?? 70)));
        $rhC = max(1, min(100, (float)($post['env_alerts_rh_crit'] ?? 90)));
        if ($rhC < $rhW) {
            $rhC = $rhW;
        }
        SettingsService::set(self::SETTING_RH_WARN, (string)$rhW, 'env_alerts');
        SettingsService::set(self::SETTING_RH_CRIT, (string)$rhC, 'env_alerts');
        SettingsService::set(
            self::SETTING_STALE_ENABLED,
            !empty($post['env_alerts_stale_enabled']) ? '1' : '0',
            'env_alerts'
        );
        $staleMin = max(15, min(10080, (int)($post['env_alerts_stale_min'] ?? 60)));
        SettingsService::set(self::SETTING_STALE_MIN, (string)$staleMin, 'env_alerts');
        SettingsService::set(
            self::SETTING_DIGEST,
            !empty($post['env_alerts_digest']) ? '1' : '0',
            'env_alerts'
        );
    }

    /** @return list<string> */
    public static function normalizeEmailList(string $raw): array
    {
        if (class_exists('PowerAlertService')) {
            return PowerAlertService::normalizeEmailList($raw);
        }
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
     * Evaluate all sensors linked to a device after SNMP poll.
     * @return array{checked:int,alerted:int}
     */
    public static function evaluateAfterDevicePoll(int $deviceId): array
    {
        if ($deviceId < 1) {
            return ['checked' => 0, 'alerted' => 0];
        }
        try {
            $rows = Database::fetchAll(
                'SELECT * FROM env_sensors
                 WHERE is_active = 1 AND device_id = ? AND last_value IS NOT NULL',
                [$deviceId]
            );
        } catch (Throwable $e) {
            return ['checked' => 0, 'alerted' => 0];
        }
        $alerted = 0;
        foreach ($rows as $s) {
            if (self::evaluateSensor($s)) {
                $alerted++;
            }
        }
        return ['checked' => count($rows), 'alerted' => $alerted];
    }

    /**
     * Evaluate one sensor; send mail on new/worse threshold breach (with cooldown).
     * @param array<string,mixed> $sensor
     */
    public static function evaluateSensor(array $sensor): bool
    {
        $cfg = self::settings();
        if (!$cfg['enabled']) {
            return false;
        }

        $sid = (int)($sensor['sensor_id'] ?? 0);
        if ($sid < 1) {
            return false;
        }

        $kind = (string)($sensor['sensor_kind'] ?? 'temperature');
        $temp = isset($sensor['last_value']) && $sensor['last_value'] !== null && $sensor['last_value'] !== ''
            ? (float)$sensor['last_value']
            : null;
        $hum = isset($sensor['last_humidity']) && $sensor['last_humidity'] !== null && $sensor['last_humidity'] !== ''
            ? (float)$sensor['last_humidity']
            : null;

        $issues = [];
        $worst = 'ok';

        if ($temp !== null && function_exists('env_sensor_threshold_status')) {
            // Thresholds and last_value are stored in °C for temperature kinds
            $st = env_sensor_threshold_status($temp, $sensor);
            if ($st === 'warn' || $st === 'crit') {
                $metric = $kind === 'humidity' ? 'humidity' : 'temperature';
                $dispVal = $temp;
                $dispUnit = (string)($sensor['unit'] ?? ($metric === 'humidity' ? '%RH' : '°C'));
                if ($metric !== 'humidity' && class_exists('TempUnitService')
                    && TempUnitService::isTempKind($kind)
                ) {
                    $dispVal = TempUnitService::fromC($temp) ?? $temp;
                    $dispUnit = TempUnitService::symbol();
                }
                $issues[] = [
                    'level' => $st,
                    'metric' => $metric,
                    'value' => $dispVal,
                    'unit' => $dispUnit,
                ];
                $worst = $st === 'crit' ? 'crit' : ($worst === 'crit' ? 'crit' : 'warn');
            }
        }

        // Combo / humidity: high RH limits from settings; optional low RH warn
        if (($kind === 'temp_humidity' || $kind === 'humidity') && $hum !== null) {
            $rhLevel = 'ok';
            if ($hum >= $cfg['rh_crit']) {
                $rhLevel = 'crit';
            } elseif ($hum >= $cfg['rh_warn']) {
                $rhLevel = 'warn';
            } elseif ($hum < 20) {
                $rhLevel = 'warn';
            }
            if ($rhLevel !== 'ok') {
                $issues[] = [
                    'level' => $rhLevel,
                    'metric' => 'humidity',
                    'value' => $hum,
                    'unit' => '%RH',
                ];
                if ($rhLevel === 'crit') {
                    $worst = 'crit';
                } elseif ($worst !== 'crit') {
                    $worst = 'warn';
                }
            }
        }

        if ($issues === [] || $worst === 'ok') {
            // Clear stored alert level when healthy (allows re-alert later)
            try {
                Database::update('env_sensors', [
                    'last_alert_level' => 'ok',
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'sensor_id = :id', [':id' => $sid]);
            } catch (Throwable $e) {
                // columns may not exist yet
            }
            // Keep history rows; mark env notices as cleared for NOC / Notifications UI
            if (class_exists('NotificationAlertStatus')) {
                NotificationAlertStatus::markEntityCleared('env_sensor', $sid);
            }
            return false;
        }

        $prev = strtolower((string)($sensor['last_alert_level'] ?? ''));
        $prevAt = (string)($sensor['last_alert_at'] ?? '');
        $cooldownSec = $cfg['cooldown_min'] * 60;

        // Cooldown: skip if same or worse level already mailed recently
        if (in_array($prev, ['warn', 'crit'], true) && $prevAt !== '') {
            $prevTs = strtotime($prevAt);
            if ($prevTs !== false && (time() - $prevTs) < $cooldownSec) {
                // Allow re-mail only if severity increased warn → crit
                if (!($prev === 'warn' && $worst === 'crit')) {
                    return false;
                }
            }
        }

        $name = (string)($sensor['name'] ?? ('Sensor #' . $sid));
        $lines = [
            'ColdAisle environmental alert',
            '',
            'Sensor: ' . $name,
            'Status: ' . strtoupper($worst),
            '',
        ];
        foreach ($issues as $iss) {
            $lines[] = sprintf(
                '- %s %s: %s %s',
                strtoupper((string)$iss['level']),
                $iss['metric'],
                rtrim(rtrim(sprintf('%.2F', (float)$iss['value']), '0'), '.'),
                $iss['unit']
            );
        }
        $lines[] = '';
        $fmtT = static function ($v) use ($kind): string {
            if ($v === null || $v === '') {
                return '—';
            }
            if (!is_numeric($v)) {
                return (string)$v;
            }
            if (class_exists('TempUnitService') && TempUnitService::isTempKind($kind) && $kind !== 'humidity') {
                return TempUnitService::format((float)$v, 1);
            }
            return (string)$v;
        };
        $lines[] = 'Thresholds (primary'
            . (class_exists('TempUnitService') && TempUnitService::isTempKind($kind) && $kind !== 'humidity'
                ? ' ' . TempUnitService::symbol() : '')
            . '): warn '
            . $fmtT($sensor['warn_low'] ?? null) . ' / ' . $fmtT($sensor['warn_high'] ?? null)
            . ' · crit ' . $fmtT($sensor['crit_low'] ?? null) . ' / ' . $fmtT($sensor['crit_high'] ?? null);
        if ($kind === 'temp_humidity' || $kind === 'humidity') {
            $lines[] = 'RH settings: warn ≥ ' . $cfg['rh_warn'] . '% · crit ≥ ' . $cfg['rh_crit'] . '%';
        }
        $lines[] = '';
        $lines[] = 'Time (UTC): ' . gmdate('Y-m-d H:i:s');

        $title = sprintf(
            'Env %s: %s',
            strtoupper($worst),
            $name
        );
        $body = implode("\n", $lines);
        $delivered = false;

        // Unified hub (in-app + subscription / default email routing)
        if (class_exists('AlertService')) {
            try {
                $stats = AlertService::emit([
                    'category' => AlertService::CAT_ENV,
                    'severity' => $worst === 'crit' ? AlertService::SEV_CRITICAL : AlertService::SEV_WARNING,
                    'title' => $title,
                    'message' => $body,
                    'entity_type' => 'env_sensor',
                    'entity_id' => $sid,
                ]);
                $delivered = ($stats['in_app'] ?? 0) > 0 || ($stats['emails'] ?? 0) > 0;
            } catch (Throwable $e) {
                App::log('Env AlertService emit: ' . $e->getMessage(), 'error');
            }
        }

        // Fallback direct email when hub unavailable or produced no mail
        if (!$delivered) {
            $emails = self::normalizeEmailList($cfg['email']);
            if ($emails && class_exists('MailService') && MailService::isEnabled()) {
                $subject = sprintf(
                    '[ColdAisle] %s env %s — %s',
                    strtoupper($worst),
                    $issues[0]['metric'] ?? 'sensor',
                    $name
                );
                try {
                    $result = MailService::send($emails, $subject, ['text' => $body]);
                    $delivered = !empty($result['ok']);
                    if (!$delivered) {
                        App::log('Env alert mail failed sensor_id=' . $sid . ': ' . ($result['message'] ?? ''), 'warning');
                    }
                } catch (Throwable $e) {
                    App::log('Env alert mail: ' . $e->getMessage(), 'error');
                }
            }
        }

        if (!$delivered) {
            return false;
        }

        try {
            Database::update('env_sensors', [
                'last_alert_level' => $worst,
                'last_alert_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'sensor_id = :id', [':id' => $sid]);
        } catch (Throwable $e) {
            // columns may not exist
        }

        App::log('Env alert sent sensor_id=' . $sid . ' level=' . $worst, 'info');
        return true;
    }

    /**
     * After scheduled SNMP cycle: re-check thresholds for sensors with values
     * (covers hosts not tied to the device just polled) and stale/offline sensors.
     *
     * @return array{threshold_checked:int,threshold_alerted:int,stale_checked:int,stale_alerted:int}
     */
    public static function runScheduledChecks(): array
    {
        $out = [
            'threshold_checked' => 0,
            'threshold_alerted' => 0,
            'stale_checked' => 0,
            'stale_alerted' => 0,
        ];
        $cfg = self::settings();
        if (!$cfg['enabled']) {
            return $out;
        }

        // Thresholds for active sensors that have a reading
        try {
            $rows = Database::fetchAll(
                'SELECT * FROM env_sensors
                 WHERE is_active = 1 AND last_value IS NOT NULL'
            );
            foreach ($rows as $s) {
                $out['threshold_checked']++;
                if (self::evaluateSensor($s)) {
                    $out['threshold_alerted']++;
                }
            }
        } catch (Throwable $e) {
            App::log('Env scheduled threshold scan: ' . $e->getMessage(), 'warning');
        }

        if (!empty($cfg['stale_enabled'])) {
            $stale = self::evaluateStaleSensors();
            $out['stale_checked'] = (int)($stale['checked'] ?? 0);
            $out['stale_alerted'] = (int)($stale['alerted'] ?? 0);
        }

        return $out;
    }

    /**
     * Find sensors that stopped reporting and notify (digest or per-sensor).
     *
     * @return array{checked:int,alerted:int,stale:list<array<string,mixed>>}
     */
    public static function evaluateStaleSensors(): array
    {
        $cfg = self::settings();
        $out = ['checked' => 0, 'alerted' => 0, 'stale' => []];
        if (!$cfg['enabled'] || empty($cfg['stale_enabled'])) {
            return $out;
        }

        $staleSec = max(15, (int)$cfg['stale_min']) * 60;
        $cutoff = time() - $staleSec;
        $cooldownSec = $cfg['cooldown_min'] * 60;

        try {
            // Sensors expected to be polled: linked to a device, or have prior last_seen_at
            $rows = Database::fetchAll(
                'SELECT * FROM env_sensors WHERE is_active = 1'
            );
        } catch (Throwable $e) {
            return $out;
        }

        $staleList = [];
        foreach ($rows as $s) {
            $out['checked']++;
            $sid = (int)($s['sensor_id'] ?? 0);
            if ($sid < 1) {
                continue;
            }
            // Only consider sensors that have reported at least once, or are device-hosted
            $seen = (string)($s['last_seen_at'] ?? '');
            $hasDevice = !empty($s['device_id']);
            if ($seen === '' && !$hasDevice) {
                continue;
            }
            if ($seen === '') {
                // Device-linked never seen — treat as stale only if older create/update
                $ref = (string)($s['updated_at'] ?? $s['created_at'] ?? '');
                $ts = $ref !== '' ? strtotime($ref) : false;
                if ($ts === false || $ts > $cutoff) {
                    continue;
                }
            } else {
                $ts = strtotime($seen);
                if ($ts === false || $ts > $cutoff) {
                    continue;
                }
            }

            // Cooldown: skip if we already mailed stale recently
            $prev = strtolower((string)($s['last_alert_level'] ?? ''));
            $prevAt = (string)($s['last_alert_at'] ?? '');
            if ($prev === 'stale' && $prevAt !== '') {
                $prevTs = strtotime($prevAt);
                if ($prevTs !== false && (time() - $prevTs) < $cooldownSec) {
                    continue;
                }
            }

            $ageMin = $ts !== false ? (int)floor((time() - $ts) / 60) : null;
            $staleList[] = [
                'sensor' => $s,
                'age_min' => $ageMin,
                'last_seen_at' => $seen !== '' ? $seen : null,
            ];
        }

        $out['stale'] = $staleList;
        if ($staleList === []) {
            return $out;
        }

        if (!empty($cfg['digest'])) {
            if (self::sendStaleDigest($staleList, $cfg)) {
                $out['alerted'] = count($staleList);
                foreach ($staleList as $item) {
                    self::markStaleAlerted((int)$item['sensor']['sensor_id']);
                }
            }
            return $out;
        }

        foreach ($staleList as $item) {
            if (self::sendStaleOne($item, $cfg)) {
                $out['alerted']++;
                self::markStaleAlerted((int)$item['sensor']['sensor_id']);
            }
        }
        return $out;
    }

    /**
     * @param list<array{sensor:array<string,mixed>,age_min:?int,last_seen_at:?string}> $staleList
     * @param array<string,mixed> $cfg
     */
    private static function sendStaleDigest(array $staleList, array $cfg): bool
    {
        $n = count($staleList);
        $lines = [
            'ColdAisle environmental sensors — offline / stale digest',
            '',
            sprintf('%d sensor(s) have not reported within %d minute(s):', $n, (int)$cfg['stale_min']),
            '',
        ];
        foreach ($staleList as $item) {
            $s = $item['sensor'];
            $name = (string)($s['name'] ?? ('#' . ($s['sensor_id'] ?? '?')));
            $age = $item['age_min'] !== null ? $item['age_min'] . ' min ago' : 'unknown age';
            $seen = $item['last_seen_at'] ?? 'never';
            $lines[] = sprintf('- %s · last seen %s (%s)', $name, $seen, $age);
        }
        $lines[] = '';
        $lines[] = 'Time (UTC): ' . gmdate('Y-m-d H:i:s');
        $title = sprintf('Env STALE: %d sensor(s) offline', $n);
        $body = implode("\n", $lines);
        return self::deliverAlert($title, $body, 'warn', 'env_sensor_stale', 0, $cfg);
    }

    /**
     * @param array{sensor:array<string,mixed>,age_min:?int,last_seen_at:?string} $item
     * @param array<string,mixed> $cfg
     */
    private static function sendStaleOne(array $item, array $cfg): bool
    {
        $s = $item['sensor'];
        $sid = (int)($s['sensor_id'] ?? 0);
        $name = (string)($s['name'] ?? ('Sensor #' . $sid));
        $age = $item['age_min'] !== null ? $item['age_min'] . ' min' : 'unknown';
        $lines = [
            'ColdAisle environmental alert — sensor offline / stale',
            '',
            'Sensor: ' . $name,
            'Status: STALE',
            'Last seen: ' . ($item['last_seen_at'] ?? 'never'),
            'Age: ' . $age,
            'Threshold: no report for ≥ ' . (int)$cfg['stale_min'] . ' minutes',
            '',
            'Time (UTC): ' . gmdate('Y-m-d H:i:s'),
        ];
        $title = 'Env STALE: ' . $name;
        return self::deliverAlert($title, implode("\n", $lines), 'warn', 'env_sensor', $sid, $cfg);
    }

    /** @param array<string,mixed> $cfg */
    private static function deliverAlert(
        string $title,
        string $body,
        string $severity,
        string $entityType,
        int $entityId,
        array $cfg
    ): bool {
        $delivered = false;
        if (class_exists('AlertService')) {
            try {
                $stats = AlertService::emit([
                    'category' => AlertService::CAT_ENV,
                    'severity' => $severity === 'crit' ? AlertService::SEV_CRITICAL : AlertService::SEV_WARNING,
                    'title' => $title,
                    'message' => $body,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId > 0 ? $entityId : null,
                ]);
                $delivered = ($stats['in_app'] ?? 0) > 0 || ($stats['emails'] ?? 0) > 0;
            } catch (Throwable $e) {
                App::log('Env stale AlertService emit: ' . $e->getMessage(), 'error');
            }
        }
        if (!$delivered) {
            $emails = self::normalizeEmailList((string)($cfg['email'] ?? ''));
            if ($emails && class_exists('MailService') && MailService::isEnabled()) {
                try {
                    $result = MailService::send($emails, '[ColdAisle] ' . $title, ['text' => $body]);
                    $delivered = !empty($result['ok']);
                } catch (Throwable $e) {
                    App::log('Env stale mail: ' . $e->getMessage(), 'error');
                }
            }
        }
        return $delivered;
    }

    private static function markStaleAlerted(int $sensorId): void
    {
        if ($sensorId < 1) {
            return;
        }
        try {
            Database::update('env_sensors', [
                'last_alert_level' => 'stale',
                'last_alert_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'sensor_id = :id', [':id' => $sensorId]);
        } catch (Throwable $e) {
            // columns may not exist
        }
    }
}
