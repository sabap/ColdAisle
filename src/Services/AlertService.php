<?php
/**
 * ColdAisle — unified alert / notification hub.
 *
 * Vision: one place for global policy, department routing, and (later) entity-specific
 * overrides. Categories expand over time: icmp, power, env, snmp, cooling, …
 *
 * Emit path for all producers:
 *   AlertService::emit([...]) → in-app notifications (+ optional email via subscriptions)
 *
 * Existing PowerAlertService / EnvSensorAlertService keep their detection logic but
 * should prefer emit() for delivery. ICMP uses emit() fully.
 */
declare(strict_types=1);

class AlertService
{
    public const CAT_ICMP = 'icmp';
    public const CAT_POWER = 'power';
    public const CAT_ENV = 'env';
    public const CAT_SNMP = 'snmp';
    public const CAT_SYSTEM = 'system';

    public const SEV_INFO = 'info';
    public const SEV_WARNING = 'warning';
    public const SEV_CRITICAL = 'critical';

    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_DEPARTMENT = 'department';
    public const SCOPE_DEVICE = 'device';
    public const SCOPE_PDU = 'pdu';

    public const SETTING_MASTER = 'alerts_master_enabled';
    public const SETTING_DEFAULT_EMAIL = 'alerts_default_email';
    public const SETTING_IN_APP = 'alerts_in_app_enabled';
    public const SETTING_EMAIL = 'alerts_email_enabled';

    /** @return list<string> */
    public static function categories(): array
    {
        return [self::CAT_ICMP, self::CAT_POWER, self::CAT_ENV, self::CAT_SNMP, self::CAT_SYSTEM];
    }

    /** @return list<string> */
    public static function severities(): array
    {
        return [self::SEV_INFO, self::SEV_WARNING, self::SEV_CRITICAL];
    }

    /**
     * @return array{
     *   master:bool,in_app:bool,email:bool,default_email:string,
     *   power:array,env:array,icmp:array
     * }
     */
    public static function settingsBundle(): array
    {
        $defaultEmail = trim((string)SettingsService::get(self::SETTING_DEFAULT_EMAIL, ''));
        if ($defaultEmail === '' && class_exists('PowerAlertService')) {
            $defaultEmail = (string)(PowerAlertService::settings()['email'] ?? '');
        }
        return [
            'master' => SettingsService::get(self::SETTING_MASTER, '1') !== '0',
            'in_app' => SettingsService::get(self::SETTING_IN_APP, '1') !== '0',
            'email' => SettingsService::get(self::SETTING_EMAIL, '1') !== '0',
            'default_email' => $defaultEmail,
            'power' => class_exists('PowerAlertService') ? PowerAlertService::settings() : [],
            'env' => class_exists('EnvSensorAlertService') ? EnvSensorAlertService::settings() : [],
            'icmp' => class_exists('IcmpMonitorService') ? IcmpMonitorService::settings() : [],
        ];
    }

    /**
     * Save hub toggles from Settings → Alerts form.
     * @param array<string,mixed> $post
     */
    public static function saveHubFromPost(array $post): void
    {
        SettingsService::set(self::SETTING_MASTER, !empty($post['alerts_master_enabled']) ? '1' : '0', 'alerts');
        SettingsService::set(self::SETTING_IN_APP, !empty($post['alerts_in_app_enabled']) ? '1' : '0', 'alerts');
        SettingsService::set(self::SETTING_EMAIL, !empty($post['alerts_email_enabled']) ? '1' : '0', 'alerts');
        $emails = self::normalizeEmailList((string)($post['alerts_default_email'] ?? ''));
        SettingsService::set(self::SETTING_DEFAULT_EMAIL, implode(', ', $emails), 'alerts');

        // Category producers (keep existing services as source of truth for thresholds)
        if (class_exists('PowerAlertService')) {
            // Map hub field names → power service expects power_alerts_*
            $p = $post;
            $p['power_alerts_enabled'] = !empty($post['power_alerts_enabled']) ? '1' : '';
            $p['power_alerts_email'] = (string)($post['power_alerts_email'] ?? $post['alerts_default_email'] ?? '');
            PowerAlertService::saveSettingsFromPost($p);
        }
        if (class_exists('EnvSensorAlertService')) {
            $p = $post;
            $p['env_alerts_enabled'] = !empty($post['env_alerts_enabled']) ? '1' : '';
            $p['env_alerts_email'] = (string)($post['env_alerts_email'] ?? '');
            EnvSensorAlertService::saveSettingsFromPost($p);
        }
        if (class_exists('IcmpMonitorService')) {
            SettingsService::set(
                IcmpMonitorService::SETTING_ENABLED,
                !empty($post['icmp_monitor_enabled']) ? '1' : '0',
                'icmp'
            );
            SettingsService::set(
                IcmpMonitorService::SETTING_ALERTS,
                !empty($post['icmp_alerts_enabled']) ? '1' : '0',
                'icmp'
            );
            $icmpEmails = self::normalizeEmailList((string)($post['icmp_alerts_email'] ?? ''));
            SettingsService::set(IcmpMonitorService::SETTING_EMAIL, implode(', ', $icmpEmails), 'icmp');
            $consec = max(1, min(20, (int)($post['icmp_consec_fail'] ?? 3)));
            SettingsService::set(IcmpMonitorService::SETTING_CONSEC_DOWN, (string)$consec, 'icmp');
            $packets = max(1, min(10, (int)($post['icmp_packets'] ?? 3)));
            SettingsService::set(IcmpMonitorService::SETTING_PACKETS, (string)$packets, 'icmp');
            $timeout = max(200, min(10000, (int)($post['icmp_timeout_ms'] ?? 1000)));
            SettingsService::set(IcmpMonitorService::SETTING_TIMEOUT_MS, (string)$timeout, 'icmp');
            $cd = max(5, min(10080, (int)($post['icmp_alert_cooldown_min'] ?? 60)));
            SettingsService::set(IcmpMonitorService::SETTING_COOLDOWN_MIN, (string)$cd, 'icmp');
        }
    }

    /**
     * Emit an alert event to matching subscriptions + optional global fallback.
     *
     * @param array{
     *   category:string,
     *   severity?:string,
     *   title:string,
     *   message:string,
     *   entity_type?:?string,
     *   entity_id?:?int,
     *   department_id?:?int,
     *   event?:string,
     *   skip_email?:bool,
     *   skip_in_app?:bool
     * } $event
     * @return array{in_app:int,emails:int,routes:int}
     */
    public static function emit(array $event): array
    {
        $hub = self::settingsBundle();
        if (!$hub['master']) {
            return ['in_app' => 0, 'emails' => 0, 'routes' => 0];
        }

        $category = strtolower(trim((string)($event['category'] ?? self::CAT_SYSTEM)));
        $severity = strtolower(trim((string)($event['severity'] ?? self::SEV_WARNING)));
        if (!in_array($severity, self::severities(), true)) {
            $severity = self::SEV_WARNING;
        }
        $title = trim((string)($event['title'] ?? 'Alert'));
        $message = trim((string)($event['message'] ?? ''));
        $entityType = isset($event['entity_type']) ? (string)$event['entity_type'] : null;
        $entityId = isset($event['entity_id']) ? (int)$event['entity_id'] : null;
        $departmentId = isset($event['department_id']) ? (int)$event['department_id'] : null;
        if ($departmentId !== null && $departmentId < 1) {
            $departmentId = null;
        }

        // Resolve department from device if not provided
        if ($departmentId === null && $entityType === 'device' && $entityId) {
            try {
                $departmentId = (int)(Database::fetchValue(
                    'SELECT department_id FROM devices WHERE device_id = ?',
                    [$entityId]
                ) ?: 0) ?: null;
            } catch (Throwable $e) {
                $departmentId = null;
            }
        }

        $routes = self::matchingSubscriptions($category, $severity, $departmentId, $entityType, $entityId);
        $stats = ['in_app' => 0, 'emails' => 0, 'routes' => count($routes)];

        $doInApp = $hub['in_app'] && empty($event['skip_in_app']);
        $doEmail = $hub['email'] && empty($event['skip_email']);

        // Always create at least one broadcast notification when no routes (global default)
        if ($doInApp) {
            $userIds = self::userIdsForRoutes($routes, $departmentId);
            if (!$userIds && !$routes) {
                // Global broadcast (user_id NULL = all users see it)
                self::insertNotification(null, $title, $message, $category, $severity, $entityType, $entityId);
                $stats['in_app']++;
            } else {
                foreach ($userIds as $uid) {
                    self::insertNotification($uid, $title, $message, $category, $severity, $entityType, $entityId);
                    $stats['in_app']++;
                }
                // Also post a global copy for ops dashboards when any global route matched
                $hasGlobal = false;
                foreach ($routes as $r) {
                    if (($r['scope'] ?? '') === self::SCOPE_GLOBAL) {
                        $hasGlobal = true;
                        break;
                    }
                }
                if ($hasGlobal || !$routes) {
                    self::insertNotification(null, $title, $message, $category, $severity, $entityType, $entityId);
                    $stats['in_app']++;
                }
            }
        }

        if ($doEmail && class_exists('MailService') && MailService::isEnabled()) {
            $emailSet = [];
            foreach ($routes as $r) {
                if (empty($r['notify_email'])) {
                    continue;
                }
                foreach (self::normalizeEmailList((string)($r['email_to'] ?? '')) as $em) {
                    $emailSet[$em] = true;
                }
            }
            if (!$emailSet) {
                // Fallbacks by category then global default
                $fallback = '';
                if ($category === self::CAT_ICMP && !empty($hub['icmp']['email'])) {
                    $fallback = (string)$hub['icmp']['email'];
                } elseif ($category === self::CAT_POWER && !empty($hub['power']['email'])) {
                    $fallback = (string)$hub['power']['email'];
                } elseif ($category === self::CAT_ENV && !empty($hub['env']['email'])) {
                    $fallback = (string)$hub['env']['email'];
                }
                if ($fallback === '') {
                    $fallback = $hub['default_email'];
                }
                foreach (self::normalizeEmailList($fallback) as $em) {
                    $emailSet[$em] = true;
                }
            }
            $emails = array_keys($emailSet);
            if ($emails) {
                $subject = '[' . App::APP_NAME . '] ' . $title;
                try {
                    $result = MailService::send($emails, $subject, ['text' => $message]);
                    if (!empty($result['ok'])) {
                        $stats['emails'] = count($emails);
                    }
                } catch (Throwable $e) {
                    App::log('AlertService email: ' . $e->getMessage(), 'error');
                }
            }
        }

        return $stats;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listSubscriptions(): array
    {
        try {
            return Database::fetchAll(
                'SELECT s.*, d.name AS department_name
                 FROM alert_subscriptions s
                 LEFT JOIN departments d ON d.department_id = s.department_id
                 ORDER BY s.scope, s.name'
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function saveSubscription(array $data, ?int $id = null): int
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            $name = 'Subscription';
        }
        $scope = strtolower(trim((string)($data['scope'] ?? self::SCOPE_GLOBAL)));
        if (!in_array($scope, [self::SCOPE_GLOBAL, self::SCOPE_DEPARTMENT, self::SCOPE_DEVICE, self::SCOPE_PDU], true)) {
            $scope = self::SCOPE_GLOBAL;
        }
        $cats = $data['categories'] ?? [];
        if (is_string($cats)) {
            $cats = preg_split('/[,\s]+/', $cats) ?: [];
        }
        $catList = [];
        foreach ((array)$cats as $c) {
            $c = strtolower(trim((string)$c));
            if (in_array($c, self::categories(), true)) {
                $catList[] = $c;
            }
        }
        if (!$catList) {
            $catList = self::categories();
        }
        $sev = strtolower(trim((string)($data['min_severity'] ?? self::SEV_WARNING)));
        if (!in_array($sev, self::severities(), true)) {
            $sev = self::SEV_WARNING;
        }
        $payload = [
            'name' => mb_substr($name, 0, 150),
            'scope' => $scope,
            'department_id' => $scope === self::SCOPE_DEPARTMENT && !empty($data['department_id'])
                ? (int)$data['department_id'] : null,
            'device_id' => $scope === self::SCOPE_DEVICE && !empty($data['device_id'])
                ? (int)$data['device_id'] : null,
            'pdu_id' => $scope === self::SCOPE_PDU && !empty($data['pdu_id'])
                ? (int)$data['pdu_id'] : null,
            'categories' => implode(',', $catList),
            'min_severity' => $sev,
            'email_to' => implode(', ', self::normalizeEmailList((string)($data['email_to'] ?? ''))),
            'notify_in_app' => !empty($data['notify_in_app']) ? 1 : 0,
            'notify_email' => !empty($data['notify_email']) ? 1 : 0,
            'is_active' => !isset($data['is_active']) || !empty($data['is_active']) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($id && $id > 0) {
            Database::update('alert_subscriptions', $payload, 'subscription_id = :id', [':id' => $id]);
            return $id;
        }
        $payload['created_at'] = date('Y-m-d H:i:s');
        return (int)Database::insert('alert_subscriptions', $payload);
    }

    public static function deleteSubscription(int $id): void
    {
        if ($id < 1) {
            return;
        }
        try {
            Database::query('DELETE FROM alert_subscriptions WHERE subscription_id = ?', [$id]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function matchingSubscriptions(
        string $category,
        string $severity,
        ?int $departmentId,
        ?string $entityType,
        ?int $entityId
    ): array {
        $all = self::listSubscriptions();
        $rank = [self::SEV_INFO => 1, self::SEV_WARNING => 2, self::SEV_CRITICAL => 3];
        $sevN = $rank[$severity] ?? 2;
        $out = [];
        foreach ($all as $s) {
            if (empty($s['is_active'])) {
                continue;
            }
            $min = strtolower((string)($s['min_severity'] ?? self::SEV_WARNING));
            if (($rank[$min] ?? 2) > $sevN) {
                continue;
            }
            $cats = array_filter(array_map('trim', explode(',', strtolower((string)($s['categories'] ?? '')))));
            if ($cats && !in_array($category, $cats, true) && !in_array('*', $cats, true)) {
                continue;
            }
            $scope = (string)($s['scope'] ?? self::SCOPE_GLOBAL);
            if ($scope === self::SCOPE_GLOBAL) {
                $out[] = $s;
                continue;
            }
            if ($scope === self::SCOPE_DEPARTMENT
                && $departmentId
                && (int)($s['department_id'] ?? 0) === $departmentId
            ) {
                $out[] = $s;
                continue;
            }
            if ($scope === self::SCOPE_DEVICE
                && $entityType === 'device'
                && $entityId
                && (int)($s['device_id'] ?? 0) === $entityId
            ) {
                $out[] = $s;
                continue;
            }
            if ($scope === self::SCOPE_PDU
                && $entityType === 'pdu'
                && $entityId
                && (int)($s['pdu_id'] ?? 0) === $entityId
            ) {
                $out[] = $s;
            }
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $routes
     * @return list<int>
     */
    private static function userIdsForRoutes(array $routes, ?int $eventDeptId): array
    {
        $ids = [];
        foreach ($routes as $r) {
            if (empty($r['notify_in_app'])) {
                continue;
            }
            $scope = (string)($r['scope'] ?? '');
            if ($scope === self::SCOPE_DEPARTMENT && !empty($r['department_id'])) {
                try {
                    $rows = Database::fetchAll(
                        'SELECT user_id FROM users WHERE is_active = 1 AND department_id = ?',
                        [(int)$r['department_id']]
                    );
                    foreach ($rows as $u) {
                        $ids[(int)$u['user_id']] = true;
                    }
                } catch (Throwable $e) {
                }
            }
            // Global in-app is handled as user_id NULL broadcast
        }
        // Department of event: if any global route wants in-app, still broadcast
        return array_map('intval', array_keys($ids));
    }

    private static function insertNotification(
        ?int $userId,
        string $title,
        string $message,
        string $category,
        string $severity,
        ?string $entityType,
        ?int $entityId
    ): void {
        // Prefer event category (icmp/power/env/…); fall back to severity for unknown
        $cat = $category !== '' ? $category : $severity;
        if ($severity === self::SEV_CRITICAL && $cat === self::CAT_SYSTEM) {
            $cat = 'warning';
        }
        try {
            Database::insert('notifications', [
                'user_id' => $userId,
                'title' => mb_substr($title, 0, 200),
                'message' => mb_substr($message, 0, 4000),
                'category' => mb_substr($cat, 0, 50),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'is_read' => 0,
            ]);
        } catch (Throwable $e) {
            App::log('AlertService notification: ' . $e->getMessage(), 'error');
        }
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
}
