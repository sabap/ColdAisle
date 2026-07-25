<?php
/**
 * ColdAisle - SNMPv3 / v2c poller
 */
declare(strict_types=1);

class SnmpPoller
{
    public static function pollAll(): array
    {
        if (class_exists('MibService')) {
            MibService::loadAll();
        }
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
        if (class_exists('MibService')) {
            MibService::loadAll();
        }
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

        $got = self::collectOidMap($session, $oidMap, static function (string $metric, $raw, ?float $num) use ($t): void {
            try {
                Database::insert('snmp_readings', [
                    'target_id' => (int)$t['target_id'],
                    'metric_name' => $metric,
                    'metric_value' => $num,
                    'metric_text' => is_string($raw) ? substr($raw, 0, 255) : null,
                ]);
            } catch (Throwable $e) {
                // history optional
            }
        });

        if ($got['ok'] === 0) {
            self::closeSession($session);
            throw new RuntimeException($got['last_error'] ?: 'All SNMP GETs failed for target');
        }

        Database::update('snmp_targets', [
            'last_success_at' => date('Y-m-d H:i:s'),
            'last_error' => $got['failed'] > 0
                ? substr(($got['last_error'] ?: 'Some OIDs failed'), 0, 500)
                : null,
        ], 'target_id = :id', [':id' => (int)$t['target_id']]);

        if (!empty($t['pdu_id']) && ($got['watts'] !== null || $got['amps'] !== null || $got['phases'] !== null)) {
            self::writePduPoll((int)$t['pdu_id'], $got['watts'], $got['amps'], $got['phases']);
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

        $got = self::collectOidMap($session, $oidMap);
        self::closeSession($session);

        if ($got['ok'] === 0) {
            throw new RuntimeException($got['last_error'] ?: 'All SNMP GETs failed for device');
        }

        Database::update('devices', [
            'snmp_last_poll_at' => date('Y-m-d H:i:s'),
            'snmp_last_poll_watts' => $got['watts'],
            'snmp_last_poll_amps' => $got['amps'],
            'snmp_fail_count' => 0,
        ], 'device_id = :id', [':id' => (int)$device['device_id']]);

        return [
            'watts' => $got['watts'],
            'amps' => $got['amps'],
            'phases' => $got['phases'],
            'ok' => $got['ok'],
            'failed' => $got['failed'],
        ];
    }

    /**
     * Poll one PDU via its site OID template only (does not use snmp_targets).
     * @return array{mode:string, message:string, watts?:?float, amps?:?float, phases?:?array}
     */
    public static function pollPduById(int $pduId): array
    {
        if (class_exists('MibService')) {
            MibService::loadAll();
        }
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
            $nPhase = is_array($result['phases'] ?? null) ? count($result['phases']) : 0;
            $msg = 'Polled via site OID template (' . $result['ok'] . ' metric(s)';
            if ($nPhase > 0) {
                $msg .= ', ' . $nPhase . ' phase(s)';
            }
            $msg .= ').';
            return [
                'mode' => 'site_template',
                'message' => $msg,
                'watts' => $result['watts'],
                'amps' => $result['amps'],
                'phases' => $result['phases'],
            ];
        }

        throw new RuntimeException(
            'No site OID template assigned. Run Discover OIDs first (Poll now does not use SNMP Targets).'
        );
    }

    /**
     * Poll a PDU using a site OID template (no snmp_targets row required).
     * @param array<string,mixed> $pdu
     * @return array{watts:?float,amps:?float,phases:?array,ok:int,failed:int}
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

        $got = self::collectOidMap($session, $oidMap);
        self::closeSession($session);

        if ($got['ok'] === 0) {
            throw new RuntimeException($got['last_error'] ?: 'All SNMP GETs failed for PDU template poll');
        }

        self::writePduPoll((int)$pdu['pdu_id'], $got['watts'], $got['amps'], $got['phases']);

        return [
            'watts' => $got['watts'],
            'amps' => $got['amps'],
            'phases' => $got['phases'],
            'ok' => $got['ok'],
            'failed' => $got['failed'],
        ];
    }

    /**
     * Persist device-total + optional multi-phase snapshot on a PDU.
     * @param array<string,array<string,?float>>|null $phases
     */
    private static function writePduPoll(int $pduId, ?float $watts, ?float $amps, ?array $phases): void
    {
        $row = [
            'last_poll_at' => date('Y-m-d H:i:s'),
            'last_poll_watts' => $watts,
            'last_poll_amps' => $amps,
        ];
        try {
            $row['last_poll_phases'] = $phases
                ? json_encode($phases, JSON_UNESCAPED_SLASHES)
                : null;
            Database::update('pdus', $row, 'pdu_id = :id', [':id' => $pduId]);
        } catch (Throwable $e) {
            // Column may not exist until Schema::ensure runs
            unset($row['last_poll_phases']);
            Database::update('pdus', $row, 'pdu_id = :id', [':id' => $pduId]);
        }

        if ($watts !== null || $amps !== null) {
            Database::insert('pdu_readings', [
                'pdu_id' => $pduId,
                'watts' => $watts,
                'amps' => $amps,
            ]);
        }
    }

    /**
     * GET each map OID and classify device totals vs phase1/2/3 metrics.
     *
     * Supported phase keys: phase1_watts, phase2_amps, phase3_volts, phase1_amps_x10, …
     * Device totals: watts, amps, amps_x10 (exact key or total_/device_ prefix).
     * If no device watts/amps but phases have them, totals are summed from phases.
     *
     * @param callable|null $onMetric function(string $metric, mixed $raw, ?float $num): void
     * @return array{
     *   watts:?float,amps:?float,
     *   phases:?array<string,array{watts:?float,amps:?float,volts:?float}>,
     *   ok:int,failed:int,last_error:?string
     * }
     */
    private static function collectOidMap($session, array $oidMap, ?callable $onMetric = null): array
    {
        $deviceWatts = null;
        $deviceAmps = null;
        /** @var array<int,array{watts:?float,amps:?float,volts:?float}> $phaseBag */
        $phaseBag = [];
        $ok = 0;
        $failed = 0;
        $lastErr = null;

        foreach ($oidMap as $metric => $oid) {
            if (is_string($metric) && str_starts_with($metric, '_')) {
                continue;
            }
            if ($oid === '' || $oid === null) {
                continue;
            }
            if (!is_string($oid) || !preg_match('/^\d/', ltrim($oid, '.'))) {
                continue;
            }
            try {
                $raw = self::get($session, ltrim($oid, '.'));
                $num = self::toNumber($raw);
                $metricKey = strtolower(trim((string)$metric));
                $num = self::applyMetricScale($metricKey, $num);
                if ($onMetric) {
                    $onMetric((string)$metric, $raw, $num);
                }

                // phase1_watts, phase2_amps_x10, phase3_volts, phase1_watts_tenths_kw, …
                if (preg_match('/^phase([123])_(watts|amps|volts)\b/i', $metricKey, $pm)) {
                    $idx = (int)$pm[1];
                    $field = strtolower($pm[2]);
                    if (!isset($phaseBag[$idx])) {
                        $phaseBag[$idx] = ['watts' => null, 'amps' => null, 'volts' => null];
                    }
                    if ($num !== null) {
                        $phaseBag[$idx][$field] = $num;
                    }
                } elseif (self::isDeviceTotalWattsKey($metricKey) && $num !== null) {
                    $deviceWatts = $num;
                } elseif (self::isDeviceTotalAmpsKey($metricKey) && $num !== null) {
                    $deviceAmps = $num;
                }
                $ok++;
            } catch (Throwable $e) {
                $failed++;
                $lastErr = $e->getMessage();
            }
        }

        $phasesOut = null;
        if ($phaseBag) {
            $phasesOut = [];
            $labels = [1 => 'L1', 2 => 'L2', 3 => 'L3'];
            ksort($phaseBag);
            foreach ($phaseBag as $idx => $fields) {
                if ($fields['watts'] === null && $fields['amps'] === null && $fields['volts'] === null) {
                    continue;
                }
                $phasesOut[$labels[$idx] ?? ('L' . $idx)] = $fields;
            }
            if (!$phasesOut) {
                $phasesOut = null;
            }
        }

        // Prefer explicit device totals; else sum phases for zone rollup
        $watts = $deviceWatts;
        $amps = $deviceAmps;
        if ($watts === null && $phasesOut) {
            $sum = 0.0;
            $any = false;
            foreach ($phasesOut as $p) {
                if ($p['watts'] !== null) {
                    $sum += (float)$p['watts'];
                    $any = true;
                }
            }
            if ($any) {
                $watts = round($sum, 3);
            }
        }
        if ($amps === null && $phasesOut) {
            $sum = 0.0;
            $any = false;
            foreach ($phasesOut as $p) {
                if ($p['amps'] !== null) {
                    $sum += (float)$p['amps'];
                    $any = true;
                }
            }
            if ($any) {
                $amps = round($sum, 3);
            }
        }

        return [
            'watts' => $watts,
            'amps' => $amps,
            'phases' => $phasesOut,
            'ok' => $ok,
            'failed' => $failed,
            'last_error' => $lastErr,
        ];
    }

    private static function applyMetricScale(string $metricKey, ?float $num): ?float
    {
        if ($num === null) {
            return null;
        }
        // tenths of kW → watts (common on some APC phase power OIDs)
        if (str_contains($metricKey, 'tenths_kw') || str_contains($metricKey, '_dkw')) {
            return round($num * 100.0, 3);
        }
        if (str_contains($metricKey, '_x100') || str_contains($metricKey, 'x100')) {
            return round($num / 100.0, 4);
        }
        if (str_contains($metricKey, 'amps_x10') || str_contains($metricKey, 'ampsx10')
            || str_ends_with($metricKey, '_x10') || str_contains($metricKey, 'x10')) {
            // Avoid matching "x100"
            if (!str_contains($metricKey, 'x100') && !str_contains($metricKey, '_x100')) {
                return round($num / 10.0, 3);
            }
        }
        return $num;
    }

    private static function isDeviceTotalWattsKey(string $key): bool
    {
        if (str_starts_with($key, 'phase')) {
            return false;
        }
        return (bool)preg_match('/^(watts?|total_watts?|device_watts?|load_watts?)(?:_x10|_x100|_tenths_kw|_dkw)?$/', $key);
    }

    private static function isDeviceTotalAmpsKey(string $key): bool
    {
        if (str_starts_with($key, 'phase')) {
            return false;
        }
        return (bool)preg_match('/^(amps?|total_amps?|device_amps?)(?:_x10|x10)?$/', $key);
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

    /**
     * Coerce SNMP values to numbers. Does not scrape digits from model strings (e.g. AP8861).
     */
    private static function toNumber($raw): ?float
    {
        if ($raw === null || $raw === false) {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            return (float)$raw;
        }
        if (!is_string($raw)) {
            if (is_numeric($raw)) {
                return (float)$raw;
            }
            return null;
        }
        $s = trim($raw);
        if ($s === '') {
            return null;
        }
        if (is_numeric($s)) {
            return (float)$s;
        }
        if (preg_match(
            '/^(?:INTEGER|Integer32|Gauge32|Counter(?:32|64)|Unsigned32|Opaque|Timeticks|TimeTicks)\s*:\s*([-+]?\d+(?:\.\d+)?)\s*$/i',
            $s,
            $m
        )) {
            return (float)$m[1];
        }
        if (preg_match('/^([-+]?\d+(?:\.\d+)?)\s*(?:W|kW|V|A|VA|%|watts?|amps?|volts?)?\s*$/i', $s, $m)) {
            return (float)$m[1];
        }
        if (preg_match('/^\(\s*(\d+)\s*\)/', $s, $m)) {
            return (float)$m[1];
        }
        return null;
    }
}
