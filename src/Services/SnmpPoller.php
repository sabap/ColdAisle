<?php
/**
 * ColdAisle - SNMPv3 / v2c poller
 */
declare(strict_types=1);

class SnmpPoller
{
    public static function pollAll(): array
    {
        $success = 0;
        $failed = 0;

        // 1) Explicit scheduled SNMP targets (is_enabled = scheduled)
        try {
            $targets = Database::fetchAll('SELECT * FROM snmp_targets WHERE is_enabled = 1');
        } catch (Throwable $e) {
            $targets = [];
        }
        foreach ($targets as $t) {
            try {
                self::pollTarget($t);
                $success++;
            } catch (Throwable $e) {
                $failed++;
                Database::update('snmp_targets', [
                    'last_error' => substr($e->getMessage(), 0, 500),
                ], 'target_id = :id', [':id' => (int)$t['target_id']]);
                App::log('SNMP poll failed for ' . $t['name'] . ': ' . $e->getMessage(), 'error');
            }
        }

        // 2) PDUs flagged for scheduled polling + site OID template (not snmp_targets)
        try {
            $pdus = Database::fetchAll(
                'SELECT * FROM pdus
                 WHERE is_active = 1 AND snmp_auto_poll = 1
                   AND snmp_site_template_id IS NOT NULL
                   AND ip_address IS NOT NULL AND ip_address <> \'\''
            );
            foreach ($pdus as $pdu) {
                try {
                    self::pollPduFromSiteTemplate($pdu, (int)$pdu['snmp_site_template_id']);
                    $success++;
                } catch (Throwable $e) {
                    $failed++;
                    App::log('PDU scheduled poll failed for ' . ($pdu['name'] ?? $pdu['pdu_id']) . ': ' . $e->getMessage(), 'error');
                }
            }
        } catch (Throwable $e) {
            // columns may not exist yet
        }

        // 3) Devices flagged for scheduled polling + site OID template
        try {
            $devices = Database::fetchAll(
                'SELECT * FROM devices
                 WHERE is_active = 1 AND snmp_auto_poll = 1
                   AND snmp_site_template_id IS NOT NULL
                   AND snmp_version IS NOT NULL AND snmp_version <> \'\''
            );
            foreach ($devices as $dev) {
                try {
                    self::pollDevice($dev);
                    $success++;
                } catch (Throwable $e) {
                    $failed++;
                    Database::update('devices', [
                        'snmp_fail_count' => (int)($dev['snmp_fail_count'] ?? 0) + 1,
                    ], 'device_id = :id', [':id' => (int)$dev['device_id']]);
                    App::log('Device SNMP poll failed for ' . ($dev['label'] ?? $dev['device_id']) . ': ' . $e->getMessage(), 'error');
                }
            }
        } catch (Throwable $e) {
            // columns may not exist yet
        }

        return ['success' => $success, 'failed' => $failed];
    }

    public static function pollTarget(array $t): void
    {
        $oidMap = json_decode($t['oid_map'] ?? '{}', true) ?: [];
        // Prefer site template OIDs when target references one
        if (!empty($t['site_template_id'])) {
            try {
                $st = Database::fetchOne(
                    'SELECT oid_map FROM snmp_site_oid_templates WHERE template_id = ? AND is_active = 1',
                    [(int)$t['site_template_id']]
                );
                if ($st && !empty($st['oid_map'])) {
                    $fromSite = json_decode((string)$st['oid_map'], true);
                    if (is_array($fromSite) && $fromSite) {
                        $oidMap = $fromSite;
                    }
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
        if (!$oidMap) {
            $oidMap = ['sysDescr' => '1.3.6.1.2.1.1.1.0'];
        }

        $session = self::openSession(
            $t['host'],
            (int)$t['port'],
            $t['snmp_version'] ?? '3',
            $t['security_name'] ?? '',
            $t['auth_protocol'] ?? '',
            Crypto::decryptQuiet($t['auth_passphrase'] ?? null) ?? '',
            $t['priv_protocol'] ?? '',
            Crypto::decryptQuiet($t['priv_passphrase'] ?? null) ?? '',
            $t['context_name'] ?? ''
        );

        $watts = null;
        $amps = null;
        $ok = 0;
        $err = 0;
        $lastErr = null;

        foreach ($oidMap as $metric => $oid) {
            if (is_string($metric) && str_starts_with($metric, '_')) {
                continue;
            }
            if ($oid === '' || $oid === null) {
                continue;
            }
            if (!is_string($oid) || !preg_match('/^\d/', $oid)) {
                continue;
            }
            try {
                $raw = self::get($session, $oid);
                $num = self::toNumber($raw);
                $metricKey = strtolower((string)$metric);
                if ($num !== null && (str_contains($metricKey, 'amps_x10') || str_contains($metricKey, 'ampsx10'))) {
                    $num = round($num / 10.0, 3);
                }
                Database::insert('snmp_readings', [
                    'target_id' => (int)$t['target_id'],
                    'metric_name' => $metric,
                    'metric_value' => $num,
                    'metric_text' => is_string($raw) ? substr($raw, 0, 255) : null,
                ]);
                if (stripos($metric, 'watt') !== false) {
                    $watts = $num;
                }
                if (stripos($metric, 'amp') !== false) {
                    $amps = $num;
                }
                $ok++;
            } catch (Throwable $e) {
                $err++;
                $lastErr = $e->getMessage();
                // Soft-fail per OID — continue remaining metrics
            }
        }

        if ($ok === 0) {
            self::closeSession($session);
            throw new RuntimeException($lastErr ?: 'All SNMP GETs failed for target');
        }

        Database::update('snmp_targets', [
            'last_success_at' => date('Y-m-d H:i:s'),
            'last_error' => $err > 0 ? substr(($lastErr ?: 'Some OIDs failed'), 0, 500) : null,
        ], 'target_id = :id', [':id' => (int)$t['target_id']]);

        if (!empty($t['pdu_id']) && ($watts !== null || $amps !== null)) {
            Database::update('pdus', [
                'last_poll_at' => date('Y-m-d H:i:s'),
                'last_poll_watts' => $watts,
                'last_poll_amps' => $amps,
            ], 'pdu_id = :id', [':id' => (int)$t['pdu_id']]);
            Database::insert('pdu_readings', [
                'pdu_id' => (int)$t['pdu_id'],
                'watts' => $watts,
                'amps' => $amps,
            ]);
        }

        self::closeSession($session);
    }

    /**
     * Poll a device using its linked site OID template (OIDs not stored on the device).
     * @param array<string,mixed> $device
     * @return array{watts:?float,amps:?float,ok:int,failed:int}
     */
    public static function pollDevice(array $device): array
    {
        require_once __DIR__ . '/SnmpDiscover.php';
        $templateId = (int)($device['snmp_site_template_id'] ?? 0);
        if ($templateId < 1) {
            throw new RuntimeException('Device has no site OID template assigned.');
        }
        $tpl = Database::fetchOne(
            'SELECT * FROM snmp_site_oid_templates WHERE template_id = ? AND is_active = 1',
            [$templateId]
        );
        if (!$tpl) {
            throw new RuntimeException('Site OID template not found or inactive.');
        }
        $oidMap = json_decode((string)($tpl['oid_map'] ?? '{}'), true) ?: [];
        if (!$oidMap) {
            throw new RuntimeException('Site OID template has an empty OID map.');
        }

        $creds = SnmpDiscover::credsFromDevice($device);
        if ($creds['host'] === '') {
            throw new RuntimeException('Device has no management/primary IP for SNMP.');
        }

        $session = self::openSession(
            $creds['host'],
            (int)$creds['port'],
            $creds['snmp_version'] ?? '3',
            $creds['security_name'] ?? '',
            $creds['auth_protocol'] ?? '',
            $creds['auth_passphrase'] ?? '',
            $creds['priv_protocol'] ?? '',
            $creds['priv_passphrase'] ?? '',
            (string)($device['snmp_v3_context'] ?? '')
        );

        $watts = null;
        $amps = null;
        $ok = 0;
        $failed = 0;
        $lastErr = null;

        foreach ($oidMap as $metric => $oid) {
            if (is_string($metric) && str_starts_with($metric, '_')) {
                continue;
            }
            if (!is_string($oid) || !preg_match('/^\d/', $oid)) {
                continue;
            }
            try {
                $raw = self::get($session, $oid);
                $num = self::toNumber($raw);
                $metricKey = strtolower((string)$metric);
                if ($num !== null && (str_contains($metricKey, 'amps_x10') || str_contains($metricKey, 'ampsx10'))) {
                    $num = round($num / 10.0, 3);
                }
                if (stripos((string)$metric, 'watt') !== false) {
                    $watts = $num;
                }
                if (stripos((string)$metric, 'amp') !== false) {
                    $amps = $num;
                }
                $ok++;
            } catch (Throwable $e) {
                $failed++;
                $lastErr = $e->getMessage();
            }
        }
        self::closeSession($session);

        if ($ok === 0) {
            throw new RuntimeException($lastErr ?: 'All SNMP GETs failed for device');
        }

        Database::update('devices', [
            'snmp_last_poll_at' => date('Y-m-d H:i:s'),
            'snmp_last_poll_watts' => $watts,
            'snmp_last_poll_amps' => $amps,
            'snmp_fail_count' => 0,
        ], 'device_id = :id', [':id' => (int)$device['device_id']]);

        return ['watts' => $watts, 'amps' => $amps, 'ok' => $ok, 'failed' => $failed];
    }

    /**
     * Poll one PDU via its site OID template only (does not use snmp_targets).
     * @return array{mode:string, message:string, watts?:?float, amps?:?float}
     */
    public static function pollPduById(int $pduId): array
    {
        $pdu = Database::fetchOne('SELECT * FROM pdus WHERE pdu_id = ? AND is_active = 1', [$pduId]);
        if (!$pdu) {
            throw new RuntimeException('PDU not found.');
        }
        if (empty($pdu['ip_address'])) {
            throw new RuntimeException('PDU has no IP address for SNMP.');
        }

        $siteTplId = (int)($pdu['snmp_site_template_id'] ?? 0);
        if ($siteTplId > 0) {
            $result = self::pollPduFromSiteTemplate($pdu, $siteTplId);
            return [
                'mode' => 'site_template',
                'message' => 'Polled via site OID template (' . $result['ok'] . ' metric(s)).',
                'watts' => $result['watts'],
                'amps' => $result['amps'],
            ];
        }

        throw new RuntimeException(
            'No site OID template assigned. Run Discover OIDs first (Poll now does not use SNMP Targets).'
        );
    }

    /**
     * Poll a PDU using a site OID template (no snmp_targets row required).
     * @param array<string,mixed> $pdu
     * @return array{watts:?float,amps:?float,ok:int,failed:int}
     */
    public static function pollPduFromSiteTemplate(array $pdu, int $templateId): array
    {
        require_once __DIR__ . '/SnmpDiscover.php';
        $tpl = Database::fetchOne(
            'SELECT * FROM snmp_site_oid_templates WHERE template_id = ? AND is_active = 1',
            [$templateId]
        );
        if (!$tpl) {
            throw new RuntimeException('Site OID template not found or inactive.');
        }
        $oidMap = json_decode((string)($tpl['oid_map'] ?? '{}'), true) ?: [];
        if (!$oidMap) {
            throw new RuntimeException('Site OID template has an empty OID map.');
        }

        $creds = SnmpDiscover::credsFromPdu($pdu);
        if ($creds['host'] === '') {
            throw new RuntimeException('PDU has no IP address for SNMP.');
        }

        $session = self::openSession(
            $creds['host'],
            (int)$creds['port'],
            $creds['snmp_version'] ?? '3',
            // v2c: openSession/get uses user as community
            ($creds['snmp_version'] ?? '') === '3'
                ? ($creds['security_name'] ?? '')
                : ($creds['community'] ?? $creds['security_name'] ?? 'public'),
            $creds['auth_protocol'] ?? '',
            $creds['auth_passphrase'] ?? '',
            $creds['priv_protocol'] ?? '',
            $creds['priv_passphrase'] ?? '',
            (string)($creds['context'] ?? $pdu['snmp_context'] ?? '')
        );

        $watts = null;
        $amps = null;
        $ok = 0;
        $failed = 0;
        $lastErr = null;

        foreach ($oidMap as $metric => $oid) {
            if (is_string($metric) && str_starts_with($metric, '_')) {
                continue;
            }
            if (!is_string($oid) || !preg_match('/^\d/', $oid)) {
                continue;
            }
            try {
                $raw = self::get($session, $oid);
                $num = self::toNumber($raw);
                $metricKey = strtolower((string)$metric);
                if ($num !== null && (str_contains($metricKey, 'amps_x10') || str_contains($metricKey, 'ampsx10'))) {
                    $num = round($num / 10.0, 3);
                }
                if (stripos((string)$metric, 'watt') !== false) {
                    $watts = $num;
                }
                if (stripos((string)$metric, 'amp') !== false) {
                    $amps = $num;
                }
                $ok++;
            } catch (Throwable $e) {
                $failed++;
                $lastErr = $e->getMessage();
            }
        }
        self::closeSession($session);

        if ($ok === 0) {
            throw new RuntimeException($lastErr ?: 'All SNMP GETs failed for PDU template poll');
        }

        Database::update('pdus', [
            'last_poll_at' => date('Y-m-d H:i:s'),
            'last_poll_watts' => $watts,
            'last_poll_amps' => $amps,
        ], 'pdu_id = :id', [':id' => (int)$pdu['pdu_id']]);

        if ($watts !== null || $amps !== null) {
            Database::insert('pdu_readings', [
                'pdu_id' => (int)$pdu['pdu_id'],
                'watts' => $watts,
                'amps' => $amps,
            ]);
        }

        return ['watts' => $watts, 'amps' => $amps, 'ok' => $ok, 'failed' => $failed];
    }

    public static function pollPdu(array $pdu): void
    {
        // Direct poll using credentials on the PDU row (sysDescr heartbeat)
        $version = (string)($pdu['snmp_version'] ?? '3');
        $communityOrUser = $version === '3'
            ? (string)($pdu['snmp_security_name'] ?? '')
            : (string)(Crypto::decryptQuiet($pdu['snmp_community'] ?? null) ?? 'public');
        $session = self::openSession(
            $pdu['ip_address'],
            (int)($pdu['snmp_port'] ?? 161),
            $version,
            $communityOrUser,
            $pdu['snmp_auth_protocol'] ?? '',
            Crypto::decryptQuiet($pdu['snmp_auth_passphrase'] ?? null) ?? '',
            $pdu['snmp_priv_protocol'] ?? '',
            Crypto::decryptQuiet($pdu['snmp_priv_passphrase'] ?? null) ?? '',
            $pdu['snmp_context'] ?? ''
        );

        $sysDescr = self::get($session, '1.3.6.1.2.1.1.1.0');
        Database::update('pdus', [
            'last_poll_at' => date('Y-m-d H:i:s'),
        ], 'pdu_id = :id', [':id' => (int)$pdu['pdu_id']]);

        Database::insert('pdu_readings', [
            'pdu_id' => (int)$pdu['pdu_id'],
            'raw_payload' => is_string($sysDescr) ? substr($sysDescr, 0, 2000) : null,
        ]);

        self::closeSession($session);
    }

    private static function openSession(
        string $host,
        int $port,
        string $version,
        string $user,
        string $authProto,
        string $authPass,
        string $privProto,
        string $privPass,
        string $context
    ) {
        if (!function_exists('snmp3_get') && !function_exists('snmp2_get')) {
            throw new RuntimeException('PHP SNMP extension not available');
        }

        return [
            'host' => $host . ($port !== 161 ? ':' . $port : ''),
            'version' => $version,
            'user' => $user,
            'authProto' => $authProto,
            'authPass' => $authPass,
            'privProto' => $privProto,
            'privPass' => $privPass,
            'context' => $context,
        ];
    }

    private static function get(array $session, string $oid)
    {
        $host = $session['host'];
        if (($session['version'] ?? '3') === '3' && function_exists('snmp3_get')) {
            $secLevel = 'noAuthNoPriv';
            if ($session['authPass'] && $session['privPass']) {
                $secLevel = 'authPriv';
            } elseif ($session['authPass']) {
                $secLevel = 'authNoPriv';
            }
            $result = @snmp3_get(
                $host,
                $session['user'],
                $secLevel,
                $session['authProto'] ?: 'SHA',
                $session['authPass'] ?: '',
                $session['privProto'] ?: 'AES',
                $session['privPass'] ?: '',
                $oid
            );
        } elseif (function_exists('snmp2_get')) {
            // For v2c, security_name is used as community
            $result = @snmp2_get($host, $session['user'] ?: 'public', $oid);
        } else {
            throw new RuntimeException('No SNMP get function available');
        }

        if ($result === false) {
            throw new RuntimeException("SNMP GET failed for OID {$oid}");
        }
        return $result;
    }

    private static function closeSession($session): void
    {
        // procedural SNMP has no session object
    }

    private static function toNumber($raw): ?float
    {
        if ($raw === null || $raw === false) {
            return null;
        }
        if (is_numeric($raw)) {
            return (float)$raw;
        }
        if (is_string($raw) && preg_match('/[-+]?\d*\.?\d+/', $raw, $m)) {
            return (float)$m[0];
        }
        return null;
    }
}
