<?php
/**
 * ColdAisle - SNMPv3 / v2c poller
 */
declare(strict_types=1);

class SnmpPoller
{
    /**
     * @return array{success:int,failed:int,skipped:int,disabled?:bool}
     */
    public static function pollAll(): array
    {
        if (class_exists('MibService')) {
            MibService::loadAll();
        }
        $success = 0;
        $failed = 0;
        $skipped = 0;

        $schedulerOn = true;
        $defaultInterval = 300;
        if (class_exists('SnmpSchedulerService')) {
            if (!SnmpSchedulerService::isEnabled()) {
                return [
                    'success' => 0,
                    'failed' => 0,
                    'skipped' => 0,
                    'disabled' => true,
                ];
            }
            $defaultInterval = SnmpSchedulerService::intervalSec();
        }

        // 1) Explicit scheduled SNMP targets (is_enabled = scheduled)
        try {
            $targets = Database::fetchAll('SELECT * FROM snmp_targets WHERE is_enabled = 1');
        } catch (Throwable $e) {
            $targets = [];
        }
        foreach ($targets as $t) {
            $iv = isset($t['poll_interval_sec']) && (int)$t['poll_interval_sec'] > 0
                ? (int)$t['poll_interval_sec']
                : $defaultInterval;
            $last = $t['last_success_at'] ?? null;
            if (class_exists('SnmpSchedulerService') && !SnmpSchedulerService::isDue(is_string($last) ? $last : null, $iv)) {
                $skipped++;
                continue;
            }
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
                $last = $pdu['last_poll_at'] ?? null;
                if (class_exists('SnmpSchedulerService')
                    && !SnmpSchedulerService::isDue(is_string($last) ? $last : null, $defaultInterval)
                ) {
                    $skipped++;
                    continue;
                }
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
                $last = $dev['snmp_last_poll_at'] ?? null;
                if (class_exists('SnmpSchedulerService')
                    && !SnmpSchedulerService::isDue(is_string($last) ? $last : null, $defaultInterval)
                ) {
                    $skipped++;
                    continue;
                }
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

        // 4) Cooling units (air handlers / pumps) with site OID template
        try {
            $cooling = Database::fetchAll(
                'SELECT * FROM cooling_units
                 WHERE is_active = 1 AND snmp_auto_poll = 1
                   AND snmp_site_template_id IS NOT NULL
                   AND primary_ip IS NOT NULL AND primary_ip <> \'\''
            );
            foreach ($cooling as $cu) {
                $last = $cu['snmp_last_poll_at'] ?? null;
                if (class_exists('SnmpSchedulerService')
                    && !SnmpSchedulerService::isDue(is_string($last) ? $last : null, $defaultInterval)
                ) {
                    $skipped++;
                    continue;
                }
                try {
                    self::pollCoolingUnit($cu);
                    $success++;
                } catch (Throwable $e) {
                    $failed++;
                    App::log(
                        'Cooling unit SNMP poll failed for ' . ($cu['name'] ?? $cu['cooling_unit_id']) . ': ' . $e->getMessage(),
                        'error'
                    );
                }
            }
        } catch (Throwable $e) {
            // columns may not exist yet
        }

        // After a multi-PDU cycle: flush digest only if hold window already elapsed
        // (so 84 PDUs in one event batch together; next scheduled poll also flushes).
        if (class_exists('PowerAlertService')) {
            try {
                $dig = PowerAlertService::flushDigestsIfDue(false);
                if (!empty($dig['flushed'])) {
                    App::log(sprintf(
                        'SNMP poll power digest: %d PDU(s), %d condition(s)',
                        (int)$dig['pdu_count'],
                        (int)$dig['alert_count']
                    ), 'info');
                }
            } catch (Throwable $e) {
                App::log('Power alert digest flush: ' . $e->getMessage(), 'warning');
            }
        }

        return ['success' => $success, 'failed' => $failed, 'skipped' => $skipped];
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
        $oidMap = self::ensureApcOutletMapKeys($oidMap);

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

        if (!empty($t['pdu_id'])) {
            $mac = $got['mac_address'] ?? null;
            if ($mac === null || $mac === '') {
                $mac = self::probeManagementMac($session);
            }
            if ($got['watts'] !== null || $got['amps'] !== null || $got['phases'] !== null
                || !empty($got['serial_no']) || !empty($got['outlets']) || ($mac !== null && $mac !== '')
            ) {
                self::writePduPoll(
                    (int)$t['pdu_id'],
                    $got['watts'],
                    $got['amps'],
                    $got['phases'],
                    $got['serial_no'] ?? null,
                    $got['outlets'] ?? null,
                    $mac
                );
            }
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

        $ver = strtolower(trim((string)($creds['snmp_version'] ?? '3')));
        if ($ver === 'v3') {
            $ver = '3';
        }
        if ($ver === '2' || $ver === 'v2c') {
            $ver = '2c';
        }
        if ($ver === 'v1') {
            $ver = '1';
        }
        // openSession treats the "user" arg as community for v1/v2c (same as PDU poll)
        $communityOrUser = ($ver === '3')
            ? (string)($creds['security_name'] ?? '')
            : (string)($creds['community'] ?? $creds['security_name'] ?? 'public');

        $session = self::openSession(
            $creds['host'],
            (int)$creds['port'],
            $ver,
            $communityOrUser,
            $creds['auth_protocol'] ?? '',
            $creds['auth_passphrase'] ?? '',
            $creds['priv_protocol'] ?? '',
            $creds['priv_passphrase'] ?? '',
            (string)($device['snmp_v3_context'] ?? $creds['context'] ?? '')
        );

        $got = self::collectOidMap($session, $oidMap);

        // Expand APC EMS probe table (MM + TH expansion modules share one flat index)
        // while session is open — template may only list probes 1–4.
        $probeNames = [];
        $probeMeta = [];
        $metrics = is_array($got['metrics'] ?? null) ? $got['metrics'] : [];
        try {
            require_once __DIR__ . '/EnvSensorPoll.php';
            $looksEms = false;
            foreach ($oidMap as $k => $v) {
                $blob = strtolower((string)$k . ' ' . (string)$v);
                if (str_contains($blob, '318.1.1.10') || str_contains($blob, 'temperature.')
                    || str_contains($blob, 'humidity.')
                ) {
                    $looksEms = true;
                    break;
                }
            }
            $sys = strtolower((string)($device['model'] ?? '') . ' ' . (string)($device['manufacturer'] ?? '')
                . ' ' . (string)($device['device_type'] ?? ''));
            if (preg_match('/ap9340|env_monitor|environmental|ems/', $sys)) {
                $looksEms = true;
            }
            $probeMeta = [];
            if ($looksEms) {
                $exp = EnvSensorPoll::expandApcEmsProbes($session, $metrics);
                $metrics = $exp['metrics'];
                $probeNames = $exp['probe_names'];
                $probeMeta = $exp['probe_meta'] ?? [];
                // AP9340 TH modules: Modular Env Manager table (module.sensor), then UIO fallback
                try {
                    $mem = EnvSensorPoll::expandMemSensors($session, $metrics, $probeNames, $probeMeta);
                    $metrics = $mem['metrics'];
                    $probeNames = $mem['probe_names'];
                    $probeMeta = $mem['probe_meta'];
                    if (!empty($mem['mem_count'])) {
                        App::log('EnvSensorPoll mem_count=' . (int)$mem['mem_count'], 'info');
                    }
                } catch (Throwable $e2) {
                    App::log('MEM/UIO sensor expand: ' . $e2->getMessage(), 'warning');
                }
                $got['metrics'] = $metrics;
                $got['ok'] = max((int)$got['ok'], count($metrics));
            }
        } catch (Throwable $e) {
            App::log('EMS probe expand: ' . $e->getMessage(), 'warning');
            $probeMeta = [];
        }

        self::closeSession($session);

        if ($got['ok'] === 0) {
            $detail = $got['last_error'] ?: 'no response';
            throw new RuntimeException(
                'All SNMP GETs failed for this device (template has OIDs but none answered). '
                . 'Last error: ' . $detail
                . '. Check SNMP version, community (v1/v2c) or v3 user, and that UDP/161 is open from IIS. '
                . 'Missing probes (e.g. humidity.4 when only 3 probes exist) are soft-failed only when other OIDs succeed — this is a total auth/reachability failure, not “sensors not created”.'
            );
        }

        Database::update('devices', [
            'snmp_last_poll_at' => date('Y-m-d H:i:s'),
            'snmp_last_poll_watts' => $got['watts'],
            'snmp_last_poll_amps' => $got['amps'],
            'snmp_fail_count' => 0,
        ], 'device_id = :id', [':id' => (int)$device['device_id']]);

        // Env sensors: temperature.* / humidity.* → last_value + env_readings
        $env = ['updated' => 0, 'readings' => 0, 'unmatched' => 0, 'keys' => 0];
        try {
            require_once __DIR__ . '/EnvSensorPoll.php';
            $env = EnvSensorPoll::applyFromDevicePoll(
                (int)$device['device_id'],
                $templateId,
                $metrics,
                $oidMap,
                $probeNames,
                $probeMeta ?? []
            );
        } catch (Throwable $e) {
            App::log('EnvSensorPoll device_id=' . (int)$device['device_id'] . ': ' . $e->getMessage(), 'warning');
        }

        // Threshold mail for env sensors on this device
        $envAlerts = ['checked' => 0, 'alerted' => 0];
        if (!empty($env['updated']) && class_exists('EnvSensorAlertService')) {
            try {
                $envAlerts = EnvSensorAlertService::evaluateAfterDevicePoll((int)$device['device_id']);
            } catch (Throwable $e) {
                App::log('EnvSensorAlert device_id=' . (int)$device['device_id'] . ': ' . $e->getMessage(), 'warning');
            }
        }
        $env['alerts'] = $envAlerts;

        return [
            'watts' => $got['watts'],
            'amps' => $got['amps'],
            'phases' => $got['phases'],
            'ok' => $got['ok'],
            'failed' => $got['failed'],
            'metrics' => $metrics,
            'probe_names' => $probeNames,
            'probe_meta' => $probeMeta ?? [],
            'env' => $env,
        ];
    }

    /**
     * Poll a cooling unit (air handler / pump) via its site OID template.
     * Stores a JSON snapshot in last_poll_json (not power-specific columns).
     *
     * @param array<string,mixed> $unit cooling_units row
     * @return array{ok:int,failed:int,metrics:array<string,mixed>,message:string}
     */
    public static function pollCoolingUnit(array $unit): array
    {
        require_once __DIR__ . '/SnmpDiscover.php';
        $templateId = (int)($unit['snmp_site_template_id'] ?? 0);
        if ($templateId < 1) {
            throw new RuntimeException('Cooling unit has no site OID template assigned. Run Discover OIDs first.');
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

        $creds = SnmpDiscover::credsFromCooling($unit);
        if ($creds['host'] === '') {
            throw new RuntimeException('Cooling unit has no primary IP for SNMP.');
        }

        $ver = strtolower(trim((string)($creds['snmp_version'] ?? '3')));
        if ($ver === 'v3') {
            $ver = '3';
        }
        if ($ver === '2' || $ver === 'v2c') {
            $ver = '2c';
        }
        if ($ver === 'v1') {
            $ver = '1';
        }
        $communityOrUser = ($ver === '3')
            ? (string)($creds['security_name'] ?? '')
            : (string)($creds['community'] ?? $creds['security_name'] ?? 'public');

        $session = self::openSession(
            $creds['host'],
            (int)$creds['port'],
            $ver,
            $communityOrUser,
            $creds['auth_protocol'] ?? '',
            $creds['auth_passphrase'] ?? '',
            $creds['priv_protocol'] ?? '',
            $creds['priv_passphrase'] ?? '',
            (string)($creds['context'] ?? $unit['snmp_context'] ?? '')
        );

        $got = self::collectOidMap($session, $oidMap);
        self::closeSession($session);

        if ((int)($got['ok'] ?? 0) === 0) {
            $detail = $got['last_error'] ?? 'no response';
            throw new RuntimeException(
                'All SNMP GETs failed for this cooling unit. Last error: ' . $detail
                . '. Check credentials, UDP/161, and the site OID template.'
            );
        }

        $metrics = is_array($got['metrics'] ?? null) ? $got['metrics'] : [];
        // Drop non-scalar noise; keep numbers/strings for UI
        $clean = [];
        foreach ($metrics as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $clean[(string)$k] = $v;
            }
        }

        $now = date('Y-m-d H:i:s');
        $snapshot = [
            'polled_at' => $now,
            'template_id' => $templateId,
            'template_name' => (string)($tpl['name'] ?? ''),
            'ok' => (int)$got['ok'],
            'failed' => (int)($got['failed'] ?? 0),
            'metrics' => $clean,
        ];

        Database::update('cooling_units', [
            'snmp_last_poll_at' => $now,
            'last_poll_json' => json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ], 'cooling_unit_id = :id', [':id' => (int)$unit['cooling_unit_id']]);

        $msg = 'Polled ' . (int)$got['ok'] . ' metric(s) from site template';
        if ((int)($got['failed'] ?? 0) > 0) {
            $msg .= ' (' . (int)$got['failed'] . ' OID soft-fail)';
        }
        $msg .= '.';

        return [
            'ok' => (int)$got['ok'],
            'failed' => (int)($got['failed'] ?? 0),
            'metrics' => $clean,
            'message' => $msg,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * Public wrappers so EnvSensorPoll can GET without duplicating SNMP glue.
     * @param array<string,mixed> $session
     * @return mixed
     */
    public static function sessionGet(array $session, string $oid)
    {
        return self::get($session, $oid);
    }

    /** @param mixed $raw */
    public static function sessionToNumber($raw): ?float
    {
        return self::toNumber($raw);
    }

    /**
     * Walk a numeric OID root; returns oid-suffix => raw value map.
     *
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public static function sessionWalk(array $session, string $root): array
    {
        $root = ltrim($root, '.');
        $result = false;
        if (!empty($session['snmp']) && $session['snmp'] instanceof \SNMP) {
            try {
                $result = @$session['snmp']->walk($root, true);
            } catch (Throwable $e) {
                $result = false;
            }
        }
        if ($result === false || !is_array($result)) {
            $host = (string)($session['host'] ?? '');
            $ver = strtolower((string)($session['version'] ?? '2c'));
            $community = (string)($session['user'] ?? 'public');
            try {
                if ($ver === '3' && function_exists('snmp3_real_walk')) {
                    $sec = 'noAuthNoPriv';
                    if (!empty($session['authPass']) && !empty($session['privPass'])) {
                        $sec = 'authPriv';
                    } elseif (!empty($session['authPass'])) {
                        $sec = 'authNoPriv';
                    }
                    $result = @snmp3_real_walk(
                        $host,
                        (string)$session['user'],
                        $sec,
                        (string)($session['authProto'] ?: 'SHA'),
                        (string)($session['authPass'] ?? ''),
                        (string)($session['privProto'] ?: 'AES'),
                        (string)($session['privPass'] ?? ''),
                        $root,
                        1_500_000,
                        0
                    );
                } elseif (($ver === '1' || $ver === 'v1') && function_exists('snmprealwalk')) {
                    $result = @snmprealwalk($host, $community, $root, 1_500_000, 0);
                } elseif (function_exists('snmp2_real_walk')) {
                    $result = @snmp2_real_walk($host, $community, $root, 1_500_000, 0);
                } elseif (function_exists('snmprealwalk')) {
                    $result = @snmprealwalk($host, $community, $root, 1_500_000, 0);
                }
            } catch (Throwable $e) {
                $result = false;
            }
        }
        if ($result === false || !is_array($result)) {
            return [];
        }
        $out = [];
        foreach ($result as $k => $v) {
            $key = (string)$k;
            // Strip root prefix if present; keep trailing instance (port.sensor)
            if (str_contains($key, $root)) {
                $pos = strpos($key, $root);
                $suffix = substr($key, $pos + strlen($root));
                $suffix = ltrim($suffix, '.');
            } elseif (preg_match('/(\d+(?:\.\d+)+)\s*$/', $key, $m)) {
                $suffix = $m[1];
            } else {
                $suffix = $key;
            }
            $out[$suffix] = $v;
        }
        return $out;
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
            $nPhase = 0;
            if (is_array($result['phases'] ?? null)) {
                foreach (['L1', 'L2', 'L3'] as $lab) {
                    if (isset($result['phases'][$lab])) {
                        $nPhase++;
                    }
                }
            }
            $nOutLive = is_array($result['outlets'] ?? null) ? count($result['outlets']) : 0;
            $nInv = isset($result['device_num_outlets']) ? (int)$result['device_num_outlets'] : 0;
            $msg = 'Polled via site OID template (' . $result['ok'] . ' metric(s)';
            if ($nPhase > 0) {
                $msg .= ', ' . $nPhase . ' phase(s)';
            }
            if ($nOutLive > 0) {
                $msg .= ', ' . $nOutLive . ' metered outlet(s)';
            } elseif ($nInv > 0) {
                $msg .= ', ' . $nInv . ' outlet(s) inventory';
                $sync = $result['outlet_sync'] ?? null;
                if (is_array($sync) && !empty($sync['added'])) {
                    $msg .= ' (+' . (int)$sync['added'] . ' rows)';
                }
            } else {
                $od = $result['outlet_diag'] ?? null;
                if (is_array($od) && ($od['status'] ?? '') === 'probe_failed') {
                    $msg .= '; no per-outlet power SNMP (phase-metered only)';
                } elseif (is_array($od) && ($od['status'] ?? '') === 'no_keys') {
                    $msg .= '; no outlet_* keys in template';
                } elseif (is_array($od) && ($od['status'] ?? '') !== 'ok') {
                    $msg .= '; outlets not read';
                }
            }
            $msg .= ').';
            return [
                'mode' => 'site_template',
                'message' => $msg,
                'watts' => $result['watts'],
                'amps' => $result['amps'],
                'phases' => $result['phases'],
                'outlets' => $result['outlets'] ?? null,
                'outlet_diag' => $result['outlet_diag'] ?? null,
                'device_num_outlets' => $result['device_num_outlets'] ?? null,
                'outlet_sync' => $result['outlet_sync'] ?? null,
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
        // Cap outlet walk from inventory (optional map key outlet_max also works)
        $nOut = (int)($pdu['num_outlets'] ?? 0);
        if ($nOut > 0 && !isset($oidMap['outlet_max'])) {
            $oidMap['outlet_max'] = (string)max(1, min(128, $nOut + 4));
        }
        // Older Discover maps often omit outlet_* keys (walk budget). If the map
        // already uses APC rPDU2 trees, attach known outlet table bases.
        $oidMap = self::ensureApcOutletMapKeys($oidMap);

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
        // Device inventory size (APC rPDU2 NumOutlets) even when no metered outlet table
        $invCount = self::probeDeviceNumOutlets($session, $oidMap, $got);
        // IF-MIB MAC even when not in the site template map
        $mac = $got['mac_address'] ?? null;
        if ($mac === null || $mac === '') {
            $mac = self::probeManagementMac($session);
            if ($mac !== null) {
                $got['mac_address'] = $mac;
            }
        }
        self::closeSession($session);

        if ($got['ok'] === 0) {
            throw new RuntimeException($got['last_error'] ?: 'All SNMP GETs failed for PDU template poll');
        }

        self::writePduPoll(
            (int)$pdu['pdu_id'],
            $got['watts'],
            $got['amps'],
            $got['phases'],
            $got['serial_no'] ?? null,
            $got['outlets'] ?? null,
            $got['mac_address'] ?? null
        );

        $outletSync = null;
        if ($invCount === null && is_array($got['outlets'] ?? null) && $got['outlets']) {
            $invCount = count($got['outlets']);
        }
        $outMode = strtolower(trim((string)($pdu['output_mode'] ?? 'outlets')));
        if ($outMode !== 'breakers' && $invCount !== null && $invCount > 0) {
            if (!function_exists('power_sync_outlet_inventory')) {
                $helpers = App::ROOT . '/includes/power_helpers.php';
                if (is_file($helpers)) {
                    require_once $helpers;
                }
            }
            if (function_exists('power_sync_outlet_inventory')) {
                $outletSync = power_sync_outlet_inventory((int)$pdu['pdu_id'], $invCount);
            }
        }

        return [
            'watts' => $got['watts'],
            'amps' => $got['amps'],
            'phases' => $got['phases'],
            'outlets' => $got['outlets'] ?? null,
            'outlet_diag' => $got['outlet_diag'] ?? null,
            'device_num_outlets' => $invCount,
            'outlet_sync' => $outletSync,
            'serial_no' => $got['serial_no'] ?? null,
            'mac_address' => $got['mac_address'] ?? null,
            'ok' => $got['ok'],
            'failed' => $got['failed'],
        ];
    }

    /**
     * Read IF-MIB ifPhysAddress (+ ifDescr) and pick a management Ethernet MAC.
     *
     * @param array<string,mixed> $session
     */
    public static function probeManagementMac(array $session): ?string
    {
        require_once __DIR__ . '/SnmpDiscover.php';
        try {
            $phys = self::sessionWalk($session, '1.3.6.1.2.1.2.2.1.6');
            if (!$phys) {
                return null;
            }
            $descr = self::sessionWalk($session, '1.3.6.1.2.1.2.2.1.2');
            $collected = [];
            foreach ($phys as $suffix => $val) {
                $idx = ltrim((string)$suffix, '.');
                // Walk may return full OID or just ifIndex
                if (preg_match('/(\d+)$/', $idx, $m)) {
                    $ifIndex = $m[1];
                } else {
                    $ifIndex = $idx;
                }
                $collected['1.3.6.1.2.1.2.2.1.6.' . $ifIndex] = [
                    'value' => $val,
                    'name' => 'ifPhysAddress',
                    'module' => 'IF-MIB',
                ];
            }
            foreach ($descr as $suffix => $val) {
                $idx = ltrim((string)$suffix, '.');
                if (preg_match('/(\d+)$/', $idx, $m)) {
                    $ifIndex = $m[1];
                } else {
                    $ifIndex = $idx;
                }
                $collected['1.3.6.1.2.1.2.2.1.2.' . $ifIndex] = [
                    'value' => $val,
                    'name' => 'ifDescr',
                    'module' => 'IF-MIB',
                ];
            }
            $hit = SnmpDiscover::extractMacFromCollected($collected);
            return $hit['value'] ?? null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Prefer map key device_num_outlets / num_outlets; else APC rPDU2 properties.
     * Also use live outlet table length when present.
     *
     * @param array<string,mixed> $oidMap
     * @param array<string,mixed> $got
     */
    private static function probeDeviceNumOutlets($session, array $oidMap, array $got): ?int
    {
        foreach (['device_num_outlets', 'num_outlets', 'outlet_count'] as $k) {
            if (!empty($oidMap[$k]) && is_string($oidMap[$k])) {
                try {
                    $raw = self::get($session, ltrim($oidMap[$k], '.'));
                    $n = self::toNumber($raw);
                    if ($n !== null && $n >= 1 && $n <= 128) {
                        return (int)$n;
                    }
                } catch (Throwable $e) {
                    // continue
                }
            }
        }
        if (is_array($got['outlets'] ?? null) && count($got['outlets']) > 0) {
            return count($got['outlets']);
        }
        $blob = '';
        foreach ($oidMap as $v) {
            if (is_string($v)) {
                $blob .= ' ' . $v;
            }
        }
        if (!str_contains($blob, '1.3.6.1.4.1.318.1.1.26')
            && !str_contains($blob, '1.3.6.1.4.1.318.1.1.12')
        ) {
            return null;
        }
        // rPDU2DevicePropertiesNumOutlets.moduleIndex (.1 typical)
        foreach ([
            '1.3.6.1.4.1.318.1.1.26.4.2.1.4.1',
            '1.3.6.1.4.1.318.1.1.12.3.1.4.0', // older rPDU outlet count
        ] as $oid) {
            try {
                $raw = self::get($session, $oid);
                $n = self::toNumber($raw);
                if ($n !== null && $n >= 1 && $n <= 128) {
                    return (int)$n;
                }
            } catch (Throwable $e) {
                // continue
            }
        }
        return null;
    }

    /**
     * When a site map already uses APC rPDU2 phase/device OIDs but Discover never
     * proposed outlet_* table bases, attach the known PowerNet columns.
     *
     * @param array<string,mixed> $oidMap
     * @return array<string,mixed>
     */
    private static function ensureApcOutletMapKeys(array $oidMap): array
    {
        foreach ($oidMap as $k => $_) {
            if (is_string($k) && preg_match('/^outlet_(amps|watts|power|current|name|state)\b/i', $k)) {
                return $oidMap;
            }
        }
        $blob = '';
        foreach ($oidMap as $v) {
            if (is_string($v)) {
                $blob .= ' ' . $v;
            }
        }
        if (!str_contains($blob, '1.3.6.1.4.1.318.1.1.26')
            && !str_contains($blob, '1.3.6.1.4.1.318.1.1.12')
        ) {
            return $oidMap;
        }
        require_once __DIR__ . '/SnmpDiscover.php';
        foreach (SnmpDiscover::apcRpdu2OutletBases() as $key => $oid) {
            if (!isset($oidMap[$key])) {
                $oidMap[$key] = $oid;
            }
        }
        return $oidMap;
    }

    /**
     * Persist device-total + optional multi-phase / per-outlet snapshots on a PDU.
     * @param array<string,mixed>|null $phases
     * @param array<string,mixed>|null $outlets
     */
    /**
     * Drop zero totals when we never got real phase/device power (load_state-only polls).
     * Still keeps last_poll_* display from raw values when provided.
     *
     * @param array<string,mixed>|null $phases
     * @return array{0:?float,1:?float}
     */
    private static function sanitizePowerTotalsForHistory(?float $watts, ?float $amps, ?array $phases): array
    {
        $phaseHasWatts = false;
        $phaseHasAmps = false;
        if (is_array($phases)) {
            foreach (['L1', 'L2', 'L3'] as $lab) {
                if (isset($phases[$lab]['watts']) && $phases[$lab]['watts'] !== null) {
                    $phaseHasWatts = true;
                }
                if (isset($phases[$lab]['amps']) && $phases[$lab]['amps'] !== null) {
                    $phaseHasAmps = true;
                }
            }
        }
        // Explicit 0 with no phase power is usually a bad/missing OID, not "facility off"
        if ($watts !== null && abs($watts) < 0.05 && !$phaseHasWatts) {
            $watts = null;
        }
        if ($amps !== null && abs($amps) < 0.005 && !$phaseHasAmps && !$phaseHasWatts) {
            $amps = null;
        }
        return [$watts, $amps];
    }

    private static function writePduPoll(
        int $pduId,
        ?float $watts,
        ?float $amps,
        ?array $phases,
        ?string $serialNo = null,
        ?array $outlets = null,
        ?string $macAddress = null
    ): void {
        // UI last_poll can still show raw; history uses sanitized totals
        $histWatts = $watts;
        $histAmps = $amps;
        [$histWatts, $histAmps] = self::sanitizePowerTotalsForHistory($watts, $amps, $phases);

        $row = [
            'last_poll_at' => date('Y-m-d H:i:s'),
            'last_poll_watts' => $watts,
            'last_poll_amps' => $amps,
        ];
        try {
            $row['last_poll_phases'] = $phases
                ? json_encode($phases, JSON_UNESCAPED_SLASHES)
                : null;
            $row['last_poll_outlets'] = $outlets
                ? json_encode($outlets, JSON_UNESCAPED_SLASHES)
                : null;
            Database::update('pdus', $row, 'pdu_id = :id', [':id' => $pduId]);
        } catch (Throwable $e) {
            // Column may not exist until Schema::ensure runs
            unset($row['last_poll_phases'], $row['last_poll_outlets']);
            try {
                $row['last_poll_phases'] = $phases
                    ? json_encode($phases, JSON_UNESCAPED_SLASHES)
                    : null;
                Database::update('pdus', $row, 'pdu_id = :id', [':id' => $pduId]);
            } catch (Throwable $e2) {
                unset($row['last_poll_phases']);
                Database::update('pdus', $row, 'pdu_id = :id', [':id' => $pduId]);
            }
        }

        // Fill empty serial / MAC from SNMP (never overwrite a value already set)
        if (($serialNo !== null && $serialNo !== '') || ($macAddress !== null && $macAddress !== '')) {
            require_once __DIR__ . '/SnmpDiscover.php';
            if ($serialNo !== null && $serialNo !== '') {
                SnmpDiscover::applySerialToPduIfEmpty($pduId, $serialNo);
            }
            if ($macAddress !== null && $macAddress !== '') {
                SnmpDiscover::applyMacToPduIfEmpty($pduId, $macAddress);
            }
        }

        // History sample (watts/amps/volts/phases) for 24h charts & reports
        if (class_exists('PowerHistoryService')) {
            try {
                $nominalLn = null;
                try {
                    $pduRow = Database::fetchOne(
                        'SELECT input_voltage, input_voltage_ln, rated_volts FROM pdus WHERE pdu_id = ?',
                        [$pduId]
                    );
                    if ($pduRow) {
                        if (isset($pduRow['input_voltage_ln']) && is_numeric($pduRow['input_voltage_ln'])) {
                            $nominalLn = (float)$pduRow['input_voltage_ln'];
                        } elseif (isset($pduRow['input_voltage']) && is_numeric($pduRow['input_voltage'])) {
                            // L–L often √3 × L–N for wye; if 208/240 treat as L–L
                            $ll = (float)$pduRow['input_voltage'];
                            $nominalLn = ($ll >= 180) ? round($ll / 1.732, 1) : $ll;
                        } elseif (isset($pduRow['rated_volts']) && is_numeric($pduRow['rated_volts'])) {
                            $ll = (float)$pduRow['rated_volts'];
                            $nominalLn = ($ll >= 180) ? round($ll / 1.732, 1) : $ll;
                        }
                    }
                } catch (Throwable $e) {
                    // ignore
                }
                PowerHistoryService::recordSample($pduId, $histWatts, $histAmps, $phases, $nominalLn);
            } catch (Throwable $e) {
                // Fallback: legacy minimal insert
                if ($histWatts !== null || $histAmps !== null) {
                    try {
                        Database::insert('pdu_readings', [
                            'pdu_id' => $pduId,
                            'watts' => $histWatts,
                            'amps' => $histAmps,
                        ]);
                    } catch (Throwable $e2) {
                        // ignore
                    }
                }
            }
        } elseif ($histWatts !== null || $histAmps !== null) {
            Database::insert('pdu_readings', [
                'pdu_id' => $pduId,
                'watts' => $histWatts,
                'amps' => $histAmps,
            ]);
        }

        // Phase util / load-state / PS alerts (settings-gated, cooldown)
        if (class_exists('PowerAlertService')) {
            try {
                if (!function_exists('power_phase_poll_decode')) {
                    $helpers = App::ROOT . '/includes/power_helpers.php';
                    if (is_file($helpers)) {
                        require_once $helpers;
                    }
                }
                PowerAlertService::evaluatePdu($pduId);
            } catch (Throwable $e) {
                App::log('PowerAlertService: ' . $e->getMessage(), 'warning');
            }
        }
    }

    /**
     * GET each map OID and classify device totals vs phase1/2/3 metrics.
     *
     * Phase keys: phase1_watts, phase2_amps_x10, phase3_volts, phase1_va_hundredths_kw,
     *   phase1_pf_x100, phase1_peak_amps_x10, phase1_load_state, …
     * L–L: phase_l12_volts, phase_l23_volts, phase_l31_volts
     * Device: watts, amps, va, pf_x1000, ps1_status, ps2_status, ps_alarm, phase_rated_amps, serial_no
     * Outlets (table bases, walked .1…N): outlet_amps_x10, outlet_watts_hundredths_kw,
     *   outlet_name, outlet_state
     * Scales: see applyMetricScale().
     *
     * @param callable|null $onMetric function(string $metric, mixed $raw, ?float $num): void
     * @return array{watts:?float,amps:?float,phases:?array,outlets:?array,serial_no:?string,ok:int,failed:int,last_error:?string}
     */
    private static function collectOidMap($session, array $oidMap, ?callable $onMetric = null): array
    {
        $deviceWatts = null;
        $deviceAmps = null;
        $deviceVa = null;
        $devicePf = null;
        $ratedAmps = null;
        $serialNo = null;
        $macAddress = null;
        $ps1 = null;
        $ps2 = null;
        $psAlarm = null;
        $ll = [];
        /** @var array<int,array<string,?float>> $phaseBag */
        $phaseBag = [];
        $ok = 0;
        $failed = 0;
        $lastErr = null;
        /** @var array<string,array{numeric:?float,raw:mixed,oid:string}> $metrics */
        $metrics = [];

        $emptyPhase = static fn(): array => [
            'watts' => null, 'amps' => null, 'volts' => null,
            'va' => null, 'pf' => null, 'peak_amps' => null, 'load_state' => null,
        ];

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
            $metricKey = strtolower(trim((string)$metric));
            // Outlet table bases are walked separately (not single-instance GETs)
            if (preg_match('/^outlet_(amps|watts|power|current|name|state)\b/', $metricKey)) {
                continue;
            }
            try {
                $raw = self::get($session, ltrim($oid, '.'));
                $num = self::toNumber($raw);
                $num = self::applyMetricScale($metricKey, $num);
                $metrics[$metricKey] = [
                    'numeric' => $num,
                    'raw' => $raw,
                    'oid' => ltrim($oid, '.'),
                ];
                if ($onMetric) {
                    $onMetric((string)$metric, $raw, $num);
                }

                // String identity metrics (serial / MAC)
                if (preg_match('/^(serial_no|serial|serialnumber)\b/', $metricKey)) {
                    require_once __DIR__ . '/SnmpDiscover.php';
                    $serialNo = SnmpDiscover::cleanSerialValue($raw) ?? $serialNo;
                    $ok++;
                    continue;
                }
                if (preg_match('/^(mac_address|mac|ifphysaddress)\b/', $metricKey)) {
                    require_once __DIR__ . '/SnmpDiscover.php';
                    $macAddress = SnmpDiscover::cleanMacValue($raw) ?? $macAddress;
                    $ok++;
                    continue;
                }

                // Allow scale suffixes: phase1_watts_hundredths_kw, phase2_amps_x10, …
                if (preg_match(
                    '/^phase([123])_(watts|amps|volts|va|pf|peak_amps|load_state)(?:_|$)/i',
                    $metricKey,
                    $pm
                )) {
                    $idx = (int)$pm[1];
                    $field = strtolower($pm[2]);
                    if (!isset($phaseBag[$idx])) {
                        $phaseBag[$idx] = $emptyPhase();
                    }
                    if ($num !== null) {
                        $phaseBag[$idx][$field] = $num;
                    }
                } elseif (preg_match('/^phase_l12_volts\b/', $metricKey) && $num !== null) {
                    $ll['L1-2'] = $num;
                } elseif (preg_match('/^phase_l23_volts\b/', $metricKey) && $num !== null) {
                    $ll['L2-3'] = $num;
                } elseif (preg_match('/^phase_l31_volts\b/', $metricKey) && $num !== null) {
                    $ll['L3-1'] = $num;
                } elseif (self::isDeviceTotalWattsKey($metricKey) && $num !== null) {
                    $deviceWatts = $num;
                } elseif (self::isDeviceTotalAmpsKey($metricKey) && $num !== null) {
                    $deviceAmps = $num;
                } elseif (preg_match('/^(va|device_va|total_va)\b/', $metricKey) && $num !== null) {
                    $deviceVa = $num;
                } elseif (preg_match('/^(pf|device_pf|power_factor)\b/', $metricKey) && $num !== null) {
                    $devicePf = $num;
                } elseif (preg_match('/^(phase_rated_amps|max_phase_amps|rated_phase_amps)\b/', $metricKey) && $num !== null) {
                    $ratedAmps = $num;
                } elseif (preg_match('/^ps1_status\b/', $metricKey) && $num !== null) {
                    $ps1 = $num;
                } elseif (preg_match('/^ps2_status\b/', $metricKey) && $num !== null) {
                    $ps2 = $num;
                } elseif (preg_match('/^ps_alarm\b/', $metricKey) && $num !== null) {
                    $psAlarm = $num;
                }
                $ok++;
            } catch (Throwable $e) {
                $failed++;
                $lastErr = $e->getMessage();
            }
        }

        // Per-outlet table walks (APC rPDU2OutletMeteredStatus* bases)
        $outletDiag = null;
        $outletsOut = self::collectOutletTables($session, $oidMap, $ok, $failed, $lastErr, $outletDiag);

        $phasesOut = null;
        if ($phaseBag || $ll || $deviceVa !== null || $devicePf !== null
            || $ps1 !== null || $ps2 !== null || $psAlarm !== null || $ratedAmps !== null
        ) {
            $phasesOut = [];
            $labels = [1 => 'L1', 2 => 'L2', 3 => 'L3'];
            ksort($phaseBag);
            foreach ($phaseBag as $idx => $fields) {
                $has = false;
                foreach ($fields as $v) {
                    if ($v !== null) {
                        $has = true;
                        break;
                    }
                }
                if (!$has) {
                    continue;
                }
                $phasesOut[$labels[$idx] ?? ('L' . $idx)] = $fields;
            }
            if ($ll) {
                $phasesOut['_ll'] = $ll;
            }
            $deviceMeta = [];
            if ($deviceVa !== null) {
                $deviceMeta['va'] = $deviceVa;
            }
            if ($devicePf !== null) {
                $deviceMeta['pf'] = $devicePf;
            }
            if ($ratedAmps !== null) {
                $deviceMeta['rated_amps'] = $ratedAmps;
            }
            if ($deviceMeta) {
                $phasesOut['_device'] = $deviceMeta;
            }
            $ps = [];
            if ($ps1 !== null) {
                $ps['ps1'] = $ps1;
            }
            if ($ps2 !== null) {
                $ps['ps2'] = $ps2;
            }
            if ($psAlarm !== null) {
                $ps['alarm'] = $psAlarm;
            }
            if ($ps) {
                $phasesOut['_ps'] = $ps;
            }
            // Only L1/L2/L3 → still a phase snapshot; meta-only is also stored
            $hasPhaseRow = isset($phasesOut['L1']) || isset($phasesOut['L2']) || isset($phasesOut['L3']);
            if (!$hasPhaseRow && !isset($phasesOut['_ll']) && !isset($phasesOut['_device']) && !isset($phasesOut['_ps'])) {
                $phasesOut = null;
            }
        }

        // Prefer explicit device totals; else sum phases for zone rollup
        $watts = $deviceWatts;
        $amps = $deviceAmps;
        if ($watts === null && is_array($phasesOut)) {
            $sum = 0.0;
            $any = false;
            foreach (['L1', 'L2', 'L3'] as $lab) {
                if (isset($phasesOut[$lab]['watts']) && $phasesOut[$lab]['watts'] !== null) {
                    $sum += (float)$phasesOut[$lab]['watts'];
                    $any = true;
                }
            }
            if ($any) {
                $watts = round($sum, 3);
            }
        }
        if ($amps === null && is_array($phasesOut)) {
            $sum = 0.0;
            $any = false;
            foreach (['L1', 'L2', 'L3'] as $lab) {
                if (isset($phasesOut[$lab]['amps']) && $phasesOut[$lab]['amps'] !== null) {
                    $sum += (float)$phasesOut[$lab]['amps'];
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
            'outlets' => $outletsOut,
            'outlet_diag' => $outletDiag,
            'serial_no' => $serialNo,
            'mac_address' => $macAddress,
            'metrics' => $metrics,
            'ok' => $ok,
            'failed' => $failed,
            'last_error' => $lastErr,
        ];
    }

    /**
     * Walk outlet table column bases: outlet_amps_x10, outlet_watts_hundredths_kw,
     * outlet_name, outlet_state → { "1": {amps, watts, name, state}, … }.
     *
     * Index style: probe base.1 then base.1.1 (APC module.outlet).
     *
     * @param-out array{status:string,message:string,columns?:int,style?:string}|null $diag
     * @return array<string,array<string,mixed>>|null
     */
    private static function collectOutletTables(
        $session,
        array $oidMap,
        int &$ok,
        int &$failed,
        ?string &$lastErr,
        ?array &$diag = null
    ): ?array {
        $diag = null;
        /** @var array<string,array{oid:string,scale_key:string}> $columns */
        $columns = [];
        foreach ($oidMap as $metric => $oid) {
            if (!is_string($oid) || !preg_match('/^\d/', ltrim($oid, '.'))) {
                continue;
            }
            $key = strtolower(trim((string)$metric));
            if (!preg_match('/^outlet_(amps|watts|power|current|name|state)\b/', $key, $m)) {
                continue;
            }
            $field = strtolower($m[1]);
            if ($field === 'power' || $field === 'watts') {
                $field = 'watts';
            } elseif ($field === 'current' || $field === 'amps') {
                $field = 'amps';
            }
            $columns[$field] = [
                'oid' => rtrim(ltrim($oid, '.'), '.'),
                'scale_key' => $key,
            ];
        }
        if (!$columns) {
            $diag = [
                'status' => 'no_keys',
                'message' => 'No outlet_* OIDs in site template map',
            ];
            return null;
        }

        $probeOid = $columns['amps']['oid']
            ?? $columns['watts']['oid']
            ?? $columns['state']['oid']
            ?? $columns['name']['oid'];
        $style = self::probeOutletIndexStyle($session, $probeOid);
        if ($style === null) {
            $diag = [
                'status' => 'probe_failed',
                'message' => 'Outlet table not present on this agent (no response at '
                    . $probeOid . '.1 or .1.1) — typical for floor PDUs or lab agents without rPDU2 outlet metering',
                'columns' => count($columns),
                'probe_base' => $probeOid,
            ];
            return null;
        }

        $max = 48;
        // Optional hint from inventory size via map meta key (numeric string)
        if (isset($oidMap['outlet_max']) && is_numeric($oidMap['outlet_max'])) {
            $max = max(1, min(128, (int)$oidMap['outlet_max']));
        }

        require_once __DIR__ . '/SnmpDiscover.php';
        $byNum = [];
        for ($i = 1; $i <= $max; $i++) {
            $suffix = $style === 'module' ? ('.1.' . $i) : ('.' . $i);
            $row = ['num' => $i];
            $got = false;
            foreach ($columns as $field => $col) {
                try {
                    $raw = self::get($session, $col['oid'] . $suffix);
                    $got = true;
                    $ok++;
                    if ($field === 'name') {
                        $name = SnmpDiscover::cleanSerialValue($raw);
                        if ($name === null && is_string($raw)) {
                            $name = trim(trim($raw), " \t\"'");
                        }
                        $row['name'] = $name !== '' ? $name : null;
                    } elseif ($field === 'state') {
                        $row['state'] = self::toNumber($raw);
                    } else {
                        $num = self::applyMetricScale($col['scale_key'], self::toNumber($raw));
                        $row[$field] = $num;
                    }
                } catch (Throwable $e) {
                    $failed++;
                    $lastErr = $e->getMessage();
                }
            }
            if (!$got) {
                // Stop after first full miss (tables are contiguous)
                if ($i === 1) {
                    $diag = [
                        'status' => 'empty',
                        'message' => 'Outlet index style ' . $style . ' probed OK but first row GETs failed',
                        'columns' => count($columns),
                        'style' => $style,
                    ];
                    return null;
                }
                break;
            }
            $byNum[(string)$i] = $row;
        }

        if ($byNum) {
            $diag = [
                'status' => 'ok',
                'message' => count($byNum) . ' outlet(s)',
                'columns' => count($columns),
                'style' => $style,
            ];
            return $byNum;
        }
        $diag = [
            'status' => 'empty',
            'message' => 'Outlet walk returned no rows',
            'columns' => count($columns),
            'style' => $style,
        ];
        return null;
    }

    /** @return 'simple'|'module'|null */
    private static function probeOutletIndexStyle($session, string $baseOid): ?string
    {
        $baseOid = rtrim(ltrim($baseOid, '.'), '.');
        try {
            self::get($session, $baseOid . '.1');
            return 'simple';
        } catch (Throwable $e) {
            // continue
        }
        try {
            self::get($session, $baseOid . '.1.1');
            return 'module';
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Metric key scale suffixes (applied before storage):
     * - hundredths_kw / 0_01kw / centikw → ×10 (APC rPDU2 power/VA in 0.01 kW units → W/VA)
     * - tenths_kw / dkw → ×100 (0.1 kW units → W)
     * - x1000 (e.g. pf_x1000) → ÷1000
     * - x100 (e.g. pf_x100) → ÷100
     * - amps_x10 / peak_amps_x10 / *_x10 on current → ÷10
     *   (does NOT apply ÷10 to watts/va — use hundredths_kw for APC power)
     */
    private static function applyMetricScale(string $metricKey, ?float $num): ?float
    {
        if ($num === null) {
            return null;
        }
        $k = strtolower($metricKey);
        // APC rPDU2 phase/device power often in hundredths of kW (42 → 420 W)
        if (str_contains($k, 'hundredths_kw') || str_contains($k, '0_01kw') || str_contains($k, 'centikw')) {
            return round($num * 10.0, 3);
        }
        if (str_contains($k, 'tenths_kw') || str_contains($k, '_dkw')) {
            return round($num * 100.0, 3);
        }
        if (str_contains($k, 'x1000') || str_contains($k, '_x1000')) {
            return round($num / 1000.0, 4);
        }
        if (str_contains($k, 'x100') || str_contains($k, '_x100')) {
            return round($num / 100.0, 4);
        }
        // Tenths of amps (and only amps/current — never watts)
        $isAmpish = (bool)preg_match('/amp|current/', $k);
        $isWattish = (bool)preg_match('/watt|power|va\b/', $k) && !str_contains($k, 'factor');
        if ($isAmpish && !$isWattish && (
            str_contains($k, 'amps_x10') || str_contains($k, 'ampsx10')
            || str_contains($k, 'peak_amps_x10') || str_ends_with($k, '_x10')
            || (str_contains($k, 'x10') && !str_contains($k, 'x100'))
        )) {
            return round($num / 10.0, 3);
        }
        return $num;
    }

    private static function isDeviceTotalWattsKey(string $key): bool
    {
        if (str_starts_with($key, 'phase')) {
            return false;
        }
        return (bool)preg_match(
            '/^(watts?|total_watts?|device_watts?|load_watts?)(?:_hundredths_kw|_tenths_kw|_dkw|_0_01kw|_centikw)?$/',
            $key
        );
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
        if (!function_exists('snmp3_get') && !function_exists('snmp2_get') && !class_exists('SNMP')) {
            throw new RuntimeException('PHP SNMP extension not available');
        }

        $hostPort = $host . ($port !== 161 ? ':' . $port : '');
        // Prefer SNMP class so we can set short timeouts (procedural snmp3_get can hang for minutes)
        $timeoutUsec = 2_000_000; // 2 seconds
        $retries = 1;
        $snmpObj = null;
        if (class_exists('SNMP')) {
            try {
                $ver = strtolower($version) === '3' ? constant('SNMP::VERSION_3') : constant('SNMP::VERSION_2c');
                if (strtolower($version) === '1') {
                    $ver = constant('SNMP::VERSION_1');
                }
                /** @var \SNMP $snmpObj */
                $snmpObj = new \SNMP($ver, $hostPort, $user ?: 'public', $timeoutUsec, $retries);
                if (defined('SNMP_VALUE_PLAIN')) {
                    $snmpObj->valueretrieval = constant('SNMP_VALUE_PLAIN');
                }
                $snmpObj->exceptions_enabled = 0;
                if (strtolower($version) === '3') {
                    $secLevel = 'noAuthNoPriv';
                    if ($authPass && $privPass) {
                        $secLevel = 'authPriv';
                    } elseif ($authPass) {
                        $secLevel = 'authNoPriv';
                    }
                    $snmpObj->setSecurity(
                        $secLevel,
                        $authProto ?: 'SHA',
                        $authPass ?: '',
                        $privProto ?: 'AES',
                        $privPass ?: '',
                        $context ?: ''
                    );
                }
            } catch (Throwable $e) {
                $snmpObj = null;
            }
        }

        return [
            'host' => $hostPort,
            'version' => $version,
            'user' => $user,
            'authProto' => $authProto,
            'authPass' => $authPass,
            'privProto' => $privProto,
            'privPass' => $privPass,
            'context' => $context,
            'snmp' => $snmpObj,
        ];
    }

    private static function get(array $session, string $oid)
    {
        $oid = ltrim($oid, '.');
        if (!empty($session['snmp']) && $session['snmp'] instanceof \SNMP) {
            try {
                $result = @$session['snmp']->get($oid);
            } catch (Throwable $e) {
                throw new RuntimeException('SNMP GET failed for OID ' . $oid . ': ' . $e->getMessage());
            }
            if ($result === false) {
                throw new RuntimeException("SNMP GET failed for OID {$oid}");
            }
            return $result;
        }

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
        } else {
            // v1 / v2c — session user holds community string
            $community = (string)($session['user'] ?: 'public');
            $ver = strtolower((string)($session['version'] ?? '2c'));
            if (($ver === '1' || $ver === 'v1') && function_exists('snmpget')) {
                $result = @snmpget($host, $community, $oid);
            } elseif (function_exists('snmp2_get')) {
                $result = @snmp2_get($host, $community, $oid);
            } elseif (function_exists('snmpget')) {
                $result = @snmpget($host, $community, $oid);
            } else {
                throw new RuntimeException('No SNMP get function available');
            }
        }

        if ($result === false) {
            throw new RuntimeException("SNMP GET failed for OID {$oid}");
        }
        return $result;
    }

    private static function closeSession($session): void
    {
        if (is_array($session) && !empty($session['snmp']) && $session['snmp'] instanceof \SNMP) {
            try {
                $session['snmp']->close();
            } catch (Throwable $e) {
                // ignore
            }
        }
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
