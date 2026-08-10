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

        // 5) UPS units (in-row / in-rack) with site OID template
        try {
            $upsList = Database::fetchAll(
                'SELECT * FROM ups_units
                 WHERE is_active = 1 AND snmp_auto_poll = 1
                   AND snmp_site_template_id IS NOT NULL
                   AND primary_ip IS NOT NULL AND primary_ip <> \'\''
            );
            foreach ($upsList as $uu) {
                $last = $uu['snmp_last_poll_at'] ?? null;
                if (class_exists('SnmpSchedulerService')
                    && !SnmpSchedulerService::isDue(is_string($last) ? $last : null, $defaultInterval)
                ) {
                    $skipped++;
                    continue;
                }
                try {
                    self::pollUpsUnit($uu);
                    $success++;
                } catch (Throwable $e) {
                    $failed++;
                    App::log(
                        'UPS SNMP poll failed for ' . ($uu['name'] ?? $uu['ups_id']) . ': ' . $e->getMessage(),
                        'error'
                    );
                }
            }
        } catch (Throwable $e) {
            // table may not exist yet
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
        $oidMap = self::ensureApcRpdu2PowerMapKeys($oidMap);

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
            $hint = SnmpDiscover::isDellManufacturer($device['manufacturer'] ?? null)
                ? 'iDRAC host, management IP, or primary IP'
                : 'management/primary IP';
            throw new RuntimeException('Device has no ' . $hint . ' for SNMP.');
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

        // Match Discover: normalize protos + resolve FQDN issues (Windows SNMP + DNS)
        $authProto = SnmpDiscover::normalizeSnmpProtocol((string)($creds['auth_protocol'] ?? 'SHA'), 'auth');
        $privProto = SnmpDiscover::normalizeSnmpProtocol((string)($creds['priv_protocol'] ?? 'AES'), 'priv');
        $secLevel = SnmpDiscover::resolveSecLevel(array_merge($creds, [
            'security_level' => (string)($device['snmp_v3_sec_level'] ?? $creds['security_level'] ?? ''),
        ]));
        $pollHost = self::resolveSnmpHost((string)$creds['host']);

        $session = self::openSession(
            $pollHost,
            (int)$creds['port'],
            $ver,
            $communityOrUser,
            $authProto,
            $creds['auth_passphrase'] ?? '',
            $privProto,
            $creds['priv_passphrase'] ?? '',
            (string)($device['snmp_v3_context'] ?? $creds['context'] ?? ''),
            $secLevel
        );
        // Prefer the same procedural snmp3_get path Discover uses (more reliable on some iDRACs)
        $session['prefer_procedural'] = true;
        $session['timeout_usec'] = 3_000_000;
        $session['retries'] = 1;
        $session['host_label'] = (string)$creds['host']; // original FQDN/IP for errors

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
            $hostHint = (string)($creds['host'] ?? '');
            $resolved = $pollHost !== $hostHint ? $pollHost : '';
            $usesIdrac = SnmpDiscover::isDellManufacturer($device['manufacturer'] ?? null)
                && trim((string)($device['idrac_host'] ?? '')) !== '';
            $msg = 'All SNMP GETs failed for this device (template has OIDs but none answered). '
                . 'Last error: ' . $detail . '. '
                . 'SNMP target: ' . ($hostHint !== '' ? $hostHint : '(empty)')
                . ($resolved !== '' ? ' → ' . $resolved : '')
                . ($usesIdrac ? ' (iDRAC field)' : '')
                . '; v' . $ver
                . ($ver === '3' ? ' user=' . $communityOrUser . ' level=' . $secLevel : '')
                . '. '
                . 'Check credentials and UDP/161 from IIS to that host. ';
            if ($usesIdrac || SnmpDiscover::isDellManufacturer($device['manufacturer'] ?? null)) {
                $msg .= 'iDRAC usually has no separate “SNMP agent IP list”; allow the IIS server via '
                    . 'iDRAC firewall / IP range filter (Connectivity → Network / firewall), and confirm '
                    . 'Services → SNMP agent is Enabled. FQDN is fine if DNS resolves on IIS. ';
            }
            $msg .= 'If Discover works but Poll fails, re-save SNMPv3 credentials on the device and try again.';
            throw new RuntimeException($msg);
        }

        $devicePatch = [
            'snmp_last_poll_at' => date('Y-m-d H:i:s'),
            'snmp_last_poll_watts' => $got['watts'],
            'snmp_last_poll_amps' => $got['amps'],
            'snmp_fail_count' => 0,
        ];
        // Identity from template keys (service_tag / system_model) → fill empty inventory fields
        if (!empty($got['serial_no']) && trim((string)($device['serial_no'] ?? '')) === '') {
            $devicePatch['serial_no'] = $got['serial_no'];
        }
        $modelFromSnmp = null;
        if (!empty($metrics['system_model']['raw'])) {
            require_once __DIR__ . '/SnmpDiscover.php';
            $modelFromSnmp = SnmpDiscover::cleanSerialValue($metrics['system_model']['raw']);
            // cleanSerialValue is fine for alphanumeric model strings; fallback strip
            if ($modelFromSnmp === null || $modelFromSnmp === '') {
                $mv = is_scalar($metrics['system_model']['raw'])
                    ? trim((string)$metrics['system_model']['raw']) : '';
                $mv = preg_replace('/^(STRING|OCTET STRING)\s*:\s*/i', '', $mv) ?? $mv;
                $modelFromSnmp = trim($mv, " \t\"'");
            }
        }
        if ($modelFromSnmp !== null && $modelFromSnmp !== ''
            && trim((string)($device['model'] ?? '')) === ''
        ) {
            $devicePatch['model'] = mb_substr($modelFromSnmp, 0, 100);
        }
        Database::update('devices', $devicePatch, 'device_id = :id', [':id' => (int)$device['device_id']]);

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

        // Custom SNMP metric thresholds (Settings → Alerts)
        $snmpThr = ['checked' => 0, 'alerted' => 0];
        if (class_exists('SnmpThresholdService')) {
            try {
                $flat = SnmpThresholdService::flattenPollMetrics(is_array($metrics) ? $metrics : []);
                if (isset($got['watts']) && $got['watts'] !== null) {
                    $flat['watts'] = (float)$got['watts'];
                }
                if (isset($got['amps']) && $got['amps'] !== null) {
                    $flat['amps'] = (float)$got['amps'];
                }
                $label = (string)($device['label'] ?? ('Device #' . (int)$device['device_id']));
                $snmpThr = SnmpThresholdService::evaluateEntity(
                    'device',
                    (int)$device['device_id'],
                    $label,
                    $flat
                );
            } catch (Throwable $e) {
                App::log('SnmpThreshold device: ' . $e->getMessage(), 'warning');
            }
        }

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
            'snmp_thresholds' => $snmpThr,
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

        if (class_exists('SnmpThresholdService')) {
            try {
                $flat = SnmpThresholdService::flattenPollMetrics(
                    is_array($got['metrics'] ?? null) ? $got['metrics'] : $metrics
                );
                SnmpThresholdService::evaluateEntity(
                    'cooling',
                    (int)$unit['cooling_unit_id'],
                    (string)($unit['name'] ?? ('Cooling #' . (int)$unit['cooling_unit_id'])),
                    $flat
                );
            } catch (Throwable $e) {
                App::log('SnmpThreshold cooling: ' . $e->getMessage(), 'warning');
            }
        }

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
     * Poll a UPS via site OID template (APC PowerNet / Symmetra).
     * @param array<string,mixed> $unit ups_units row
     * @return array{ok:int,failed:int,metrics:array<string,mixed>,message:string}
     */
    public static function pollUpsUnit(array $unit): array
    {
        require_once __DIR__ . '/SnmpDiscover.php';
        if (is_file(App::ROOT . '/includes/ups_helpers.php')) {
            require_once App::ROOT . '/includes/ups_helpers.php';
        }
        $templateId = (int)($unit['snmp_site_template_id'] ?? 0);
        if ($templateId < 1) {
            throw new RuntimeException('UPS has no site OID template. Run Discover OIDs or assign the APC UPS template.');
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

        $host = trim((string)($unit['primary_ip'] ?? ''));
        if ($host === '') {
            throw new RuntimeException('UPS has no primary IP for SNMP.');
        }
        $ver = strtolower(trim((string)($unit['snmp_version'] ?? '3')));
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
            ? (string)($unit['snmp_security_name'] ?? '')
            : (string)(Crypto::decryptQuiet($unit['snmp_community'] ?? null) ?? 'public');
        $authProto = SnmpDiscover::normalizeSnmpProtocol((string)($unit['snmp_auth_protocol'] ?? 'SHA'), 'auth');
        $privProto = SnmpDiscover::normalizeSnmpProtocol((string)($unit['snmp_priv_protocol'] ?? 'AES'), 'priv');
        $authPass = (string)(Crypto::decryptQuiet($unit['snmp_auth_passphrase'] ?? null) ?? '');
        $privPass = (string)(Crypto::decryptQuiet($unit['snmp_priv_passphrase'] ?? null) ?? '');
        $secLevel = SnmpDiscover::resolveSecLevel([
            'security_level' => (string)($unit['snmp_v3_sec_level'] ?? ''),
            'auth_passphrase' => $authPass,
            'priv_passphrase' => $privPass,
        ]);
        $pollHost = self::resolveSnmpHost($host);

        $session = self::openSession(
            $pollHost,
            (int)($unit['snmp_port'] ?? 161),
            $ver,
            $communityOrUser,
            $authProto,
            $authPass,
            $privProto,
            $privPass,
            (string)($unit['snmp_context'] ?? ''),
            $secLevel
        );
        $got = self::collectOidMap($session, $oidMap);
        self::closeSession($session);

        if ((int)($got['ok'] ?? 0) === 0) {
            throw new RuntimeException(
                'All SNMP GETs failed for this UPS. Last error: ' . ($got['last_error'] ?? 'no response')
            );
        }

        $metrics = is_array($got['metrics'] ?? null) ? $got['metrics'] : [];
        // Also expose collectOidMap serial when key matched during GET
        if (!empty($got['serial_no']) && empty($metrics['serial_no'])) {
            $metrics['serial_no'] = ['raw' => $got['serial_no'], 'numeric' => null];
        }
        $flat = class_exists('SnmpThresholdService')
            ? SnmpThresholdService::flattenPollMetrics($metrics)
            : [];

        $loadPct = $flat['output_load'] ?? null;
        $battPct = $flat['battery_capacity'] ?? null;
        $outStatusCode = $flat['output_status'] ?? null;
        $runtimeMin = null;
        if (isset($flat['runtime_ticks'])) {
            // TimeTicks: hundredths of a second
            $runtimeMin = round(((float)$flat['runtime_ticks']) / 100.0 / 60.0, 1);
        }
        $inV = $flat['input_voltage'] ?? null;
        $outV = $flat['output_voltage'] ?? null;
        $tempC = $flat['battery_temp_c'] ?? null;
        $statusLabel = null;
        if (function_exists('ups_output_status_label') && $outStatusCode !== null) {
            $statusLabel = ups_output_status_label($outStatusCode);
        } elseif ($outStatusCode !== null) {
            $statusLabel = (string)$outStatusCode;
        }

        // Human-readable sysUpTime (MIB-II TimeTicks)
        $sysUptimeLabel = null;
        foreach (['sysuptime', 'sysUpTime', 'uptime', 'sys_uptime'] as $uk) {
            if (isset($flat[$uk])) {
                $sysUptimeLabel = function_exists('ups_format_timeticks')
                    ? ups_format_timeticks($flat[$uk])
                    : (string)$flat[$uk];
                break;
            }
            if (isset($metrics[$uk])) {
                $n = is_array($metrics[$uk])
                    ? ($metrics[$uk]['numeric'] ?? null)
                    : (is_numeric($metrics[$uk]) ? $metrics[$uk] : null);
                if ($n !== null && function_exists('ups_format_timeticks')) {
                    $sysUptimeLabel = ups_format_timeticks($n);
                    break;
                }
            }
        }
        // Annotate metrics for UI display (formatted strings alongside raw)
        $metricsDisplay = [];
        foreach ($metrics as $mk => $mv) {
            $metricsDisplay[(string)$mk] = function_exists('ups_format_metric_display')
                ? ups_format_metric_display((string)$mk, $mv)
                : (is_array($mv) ? ($mv['numeric'] ?? $mv['raw'] ?? '') : $mv);
        }

        $now = date('Y-m-d H:i:s');
        $snapshot = [
            'polled_at' => $now,
            'template_id' => $templateId,
            'template_name' => (string)($tpl['name'] ?? ''),
            'ok' => (int)$got['ok'],
            'failed' => (int)($got['failed'] ?? 0),
            'metrics' => $metrics,
            'metrics_display' => $metricsDisplay,
            'derived' => [
                'load_pct' => $loadPct,
                'battery_pct' => $battPct,
                'runtime_min' => $runtimeMin,
                'output_status_label' => $statusLabel,
                'sysuptime_label' => $sysUptimeLabel,
            ],
        ];

        $patch = [
            'snmp_last_poll_at' => $now,
            'last_poll_json' => json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'last_output_status' => $statusLabel,
            'last_load_pct' => $loadPct,
            'last_battery_pct' => $battPct,
            'last_runtime_min' => $runtimeMin,
            'last_input_voltage' => $inV,
            'last_output_voltage' => $outV,
            'last_internal_temp_c' => $tempC,
            'updated_at' => $now,
        ];
        // Fill empty model from SNMP
        if (!empty($flat['model']) || !empty($metrics['model']['raw'])) {
            $modelRaw = $metrics['model']['raw'] ?? $flat['model'] ?? null;
            if (is_scalar($modelRaw) && trim((string)($unit['model'] ?? '')) === '') {
                $mv = preg_replace('/^(STRING|OCTET STRING)\s*:\s*/i', '', trim((string)$modelRaw)) ?? '';
                $mv = trim($mv, " \t\"'");
                if ($mv !== '') {
                    $patch['model'] = mb_substr($mv, 0, 100);
                }
            }
        }
        // Serial from SNMP → inventory field (always update when a clean serial is polled)
        $serialFromSnmp = null;
        if (!empty($got['serial_no'])) {
            $serialFromSnmp = SnmpDiscover::cleanSerialValue($got['serial_no'])
                ?? (is_scalar($got['serial_no']) ? trim((string)$got['serial_no'], " \t\"'") : null);
        }
        if (($serialFromSnmp === null || $serialFromSnmp === '') && function_exists('ups_serial_from_metrics')) {
            $serialFromSnmp = ups_serial_from_metrics($metrics);
        }
        if ($serialFromSnmp !== null && $serialFromSnmp !== '') {
            $patch['serial_no'] = mb_substr($serialFromSnmp, 0, 100);
        }
        // Manufacture date from PowerNet upsAdvIdentDateOfManufacture when present
        $mfgDate = null;
        if (function_exists('ups_manufacture_date_from_metrics')) {
            $mfgDate = ups_manufacture_date_from_metrics($metrics);
        }
        if ($mfgDate === null && function_exists('ups_parse_manufacture_date')) {
            if (!empty($flat['manufacture_date'])) {
                $mfgDate = ups_parse_manufacture_date($flat['manufacture_date']);
            } elseif (!empty($metrics['manufacture_date'])) {
                $mfgDate = ups_parse_manufacture_date($metrics['manufacture_date']);
            }
        }
        if ($mfgDate !== null && $mfgDate !== '') {
            $patch['manufacture_date'] = $mfgDate;
        }
        Database::update('ups_units', $patch, 'ups_id = :id', [':id' => (int)$unit['ups_id']]);

        // History sample for Power / zone charts
        if (class_exists('UpsHistoryService')) {
            $estW = null;
            if ($loadPct !== null) {
                if ($unit['rated_kw'] !== null && $unit['rated_kw'] !== '') {
                    $estW = (float)$unit['rated_kw'] * 1000.0 * ((float)$loadPct / 100.0);
                } elseif ($unit['rated_kva'] !== null && $unit['rated_kva'] !== '') {
                    $estW = (float)$unit['rated_kva'] * 1000.0 * 0.9 * ((float)$loadPct / 100.0);
                }
            }
            try {
                UpsHistoryService::recordSample(
                    (int)$unit['ups_id'],
                    $loadPct !== null ? (float)$loadPct : null,
                    $battPct !== null ? (float)$battPct : null,
                    $runtimeMin !== null ? (float)$runtimeMin : null,
                    $statusLabel,
                    $estW
                );
            } catch (Throwable $e) {
                App::log('UpsHistory record: ' . $e->getMessage(), 'warning');
            }
        }

        if (class_exists('SnmpThresholdService')) {
            try {
                SnmpThresholdService::evaluateEntity(
                    'ups',
                    (int)$unit['ups_id'],
                    (string)($unit['name'] ?? ('UPS #' . (int)$unit['ups_id'])),
                    array_merge($flat, array_filter([
                        'load_pct' => $loadPct,
                        'battery_pct' => $battPct,
                        'runtime_min' => $runtimeMin,
                    ], static fn($v) => $v !== null))
                );
            } catch (Throwable $e) {
                App::log('SnmpThreshold ups: ' . $e->getMessage(), 'warning');
            }
        }

        // Critical operational state → alert hub (on battery / bypass)
        if ($statusLabel && class_exists('AlertService') && preg_match('/battery|bypass|off|emergency/i', $statusLabel)
            && !preg_match('/on line|online/i', $statusLabel)
        ) {
            try {
                AlertService::emit([
                    'category' => AlertService::CAT_POWER,
                    'severity' => preg_match('/battery|emergency|off/i', $statusLabel)
                        ? AlertService::SEV_CRITICAL : AlertService::SEV_WARNING,
                    'title' => 'UPS ' . $statusLabel . ': ' . ($unit['name'] ?? ''),
                    'message' => "UPS operational status: {$statusLabel}\n"
                        . 'Load: ' . ($loadPct !== null ? $loadPct . '%' : '—') . "\n"
                        . 'Battery: ' . ($battPct !== null ? $battPct . '%' : '—') . "\n"
                        . 'Runtime: ' . ($runtimeMin !== null ? $runtimeMin . ' min' : '—') . "\n"
                        . 'Time: ' . date('c'),
                    'entity_type' => 'ups',
                    'entity_id' => (int)$unit['ups_id'],
                ]);
            } catch (Throwable $e) {
                App::log('UPS status alert: ' . $e->getMessage(), 'warning');
            }
        }

        $msg = 'Polled ' . (int)$got['ok'] . ' UPS metric(s)';
        if ($loadPct !== null) {
            $msg .= ' · load ' . $loadPct . '%';
        }
        if ($battPct !== null) {
            $msg .= ' · batt ' . $battPct . '%';
        }
        if ($statusLabel) {
            $msg .= ' · ' . $statusLabel;
        }

        return [
            'ok' => (int)$got['ok'],
            'failed' => (int)($got['failed'] ?? 0),
            'metrics' => $metrics,
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
                'ok' => $result['ok'] ?? 0,
                'failed' => $result['failed'] ?? 0,
                'metrics' => $result['metrics'] ?? null,
                'load_diag' => $result['load_diag'] ?? null,
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
        // Same for total/phase power: Ident DevicePowerWatts often stays 0 on AP78xx
        // without L–L calibration — inject rPDU2DeviceStatusPower + phase I/V.
        $oidMap = self::ensureApcRpdu2PowerMapKeys($oidMap);

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
        // Recover AP7862 / AP78xx load when Ident watts=0 or map mis-labeled current as watts
        $got = self::recoverApcPduLoad($session, $got, $pdu, $oidMap);
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

        $loadDiag = self::buildPduLoadDiag($got, $pdu);

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
            'metrics' => $got['metrics'] ?? null,
            'load_diag' => $loadDiag,
        ];
    }

    /**
     * Human-readable load diagnosis for Poll now (no production access required).
     *
     * @param array<string,mixed> $got
     * @param array<string,mixed> $pdu
     * @return array{summary:string,hints:list<string>,raw:list<array{key:string,oid:string,raw:mixed,numeric:?float}>}
     */
    private static function buildPduLoadDiag(array $got, array $pdu): array
    {
        $raw = [];
        $metrics = is_array($got['metrics'] ?? null) ? $got['metrics'] : [];
        foreach ($metrics as $k => $info) {
            if (!is_array($info)) {
                continue;
            }
            $raw[] = [
                'key' => (string)$k,
                'oid' => (string)($info['oid'] ?? ''),
                'raw' => is_scalar($info['raw'] ?? null) ? $info['raw'] : json_encode($info['raw'] ?? null),
                'numeric' => isset($info['numeric']) && is_numeric($info['numeric'])
                    ? (float)$info['numeric'] : null,
            ];
        }
        $hints = [];
        $w = $got['watts'] ?? null;
        $a = $got['amps'] ?? null;
        $phaseAmps = [];
        if (is_array($got['phases'] ?? null)) {
            foreach (['L1', 'L2', 'L3'] as $lab) {
                $pa = $got['phases'][$lab]['amps'] ?? null;
                if ($pa !== null) {
                    $phaseAmps[$lab] = (float)$pa;
                }
            }
        }
        $sumA = $phaseAmps ? array_sum($phaseAmps) : (($a !== null) ? (float)$a : 0.0);

        // Inspect classic Ident voltage / power from metrics
        $identW = null;
        $identV = null;
        foreach ($raw as $row) {
            $oid = ltrim($row['oid'], '.');
            if (preg_match('/^1\.3\.6\.1\.4\.1\.318\.1\.1\.12\.1\.16(?:\.|$)/', $oid)) {
                $identW = $row['numeric'];
            }
            if (preg_match('/^1\.3\.6\.1\.4\.1\.318\.1\.1\.12\.1\.15(?:\.|$)/', $oid)
                || preg_match('/input_volts|line_volts/', $row['key'])
            ) {
                $identV = $row['numeric'];
            }
        }

        if ($w !== null && abs((float)$w) >= 0.5) {
            $summary = 'Load OK: ' . round((float)$w) . ' W'
                . ($sumA > 0 ? ' · ' . round($sumA, 2) . ' A' : '');
        } elseif ($sumA < 0.01) {
            $summary = 'SNMP reports ~0 A on all phase/bank load indexes and no usable device watts.';
            $hints[] = 'On the NMC web UI, confirm Load / Amps is non-zero for this PDU right now.';
            $hints[] = 'snmpget rPDULoadStatusLoad.1–.6 (…12.2.3.1.1.2.1 … .6) — values are tenths of amps.';
            $hints[] = 'AP7xxx Ident power (…12.1.16.0) stays 0 until Line-to-Line voltage (…12.1.15.0) is set on the NMC.';
            if ($identV !== null && abs((float)$identV) < 1) {
                $hints[] = 'L–L / input voltage OID returned ' . $identV
                    . ' — set it on the NMC (or set Input voltage on the ColdAisle PDU form) so watts can be calculated from amps.';
            }
            if ($identW !== null && abs((float)$identW) < 0.5) {
                $hints[] = 'rPDUIdentDevicePowerWatts=' . $identW
                    . ' with input_volts set still yields 0 W — NMC calculates P from V×I; I is also 0 over SNMP.';
            }
            // Surface full load-status tenths walk if present
            foreach ($raw as $row) {
                if (($row['key'] ?? '') === '_apc_load_status_tenths' && !empty($row['raw'])) {
                    $hints[] = 'LoadStatus tenths-of-A by index: ' . (string)$row['raw'];
                    break;
                }
            }
            $invV = self::pduNominalVoltsLn($pdu);
            if ($invV === null && ($identV === null || abs((float)$identV) < 1)) {
                $hints[] = 'ColdAisle PDU inventory has no input voltage — set e.g. 120 or 208 for I×V estimates when amps are present.';
            }
            $hints[] = 'If the NMC web UI shows real load while SNMP is all zeros, this is an NMC SNMP/export issue (not ColdAisle map math).';
        } else {
            $summary = 'Have amps (' . round($sumA, 2) . ' A) but watts still ~0 after recovery.';
            $hints[] = 'Check input_volts / phase volts and PF; I×V may have been skipped.';
        }

        return [
            'summary' => $summary,
            'hints' => $hints,
            'raw' => $raw,
            'phase_amps' => $phaseAmps,
            'ident_watts' => $identW,
            'ident_volts' => $identV,
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
        // Per-outlet metered table is rPDU2 26.9 only. Classic AP7862 (12.x phase/bank
        // metered) has no outlet power table — injecting bases wastes GETs and can
        // burn the poll window without improving load.
        if (!str_contains($blob, '1.3.6.1.4.1.318.1.1.26.9')) {
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
     * Ensure APC maps include rPDU2 total power + basic phase I/V/P.
     * Discover often saved only rPDUIdentDevicePowerWatts (plain watts → 0 on many
     * AP7862/AP78xx without L–L calibration). Injecting these does not overwrite
     * existing keys; poller prefers non-zero among device total keys.
     *
     * @param array<string,mixed> $oidMap
     * @return array<string,mixed>
     */
    private static function ensureApcRpdu2PowerMapKeys(array $oidMap): array
    {
        $blob = '';
        foreach ($oidMap as $v) {
            if (is_string($v)) {
                $blob .= ' ' . $v;
            }
        }
        $isClassicRpdu = str_contains($blob, '1.3.6.1.4.1.318.1.1.12');
        $isRpdu2 = str_contains($blob, '1.3.6.1.4.1.318.1.1.26');
        $isApc = $isClassicRpdu || $isRpdu2 || str_contains($blob, '1.3.6.1.4.1.318.1.1.1');
        if (!$isApc) {
            return $oidMap;
        }

        $inject = [];
        // Classic AP7862 path: Ident watts + L–L voltage + phase/bank amps (12.x)
        if ($isClassicRpdu) {
            if (!isset($oidMap['watts']) && !isset($oidMap['watts_hundredths_kw'])) {
                $inject['watts'] = '1.3.6.1.4.1.318.1.1.12.1.16.0';
            }
            if (!isset($oidMap['input_volts']) && !isset($oidMap['phase1_volts'])) {
                $inject['input_volts'] = '1.3.6.1.4.1.318.1.1.12.1.15.0';
            }
            $hasPhaseAmps = false;
            foreach ($oidMap as $k => $_) {
                if (is_string($k) && preg_match('/^phase[123]_amps/', $k)) {
                    $hasPhaseAmps = true;
                    break;
                }
            }
            if (!$hasPhaseAmps) {
                $inject['phase1_amps_x10'] = '1.3.6.1.4.1.318.1.1.12.2.3.1.1.2.1';
                $inject['phase2_amps_x10'] = '1.3.6.1.4.1.318.1.1.12.2.3.1.1.2.2';
                $inject['phase3_amps_x10'] = '1.3.6.1.4.1.318.1.1.12.2.3.1.1.2.3';
            }
        }
        // rPDU2 total power only when map already uses 26.x (or pure rPDU2 maps)
        if ($isRpdu2) {
            $hasRpdu2Power = false;
            foreach ($oidMap as $oid) {
                if (!is_string($oid)) {
                    continue;
                }
                if (preg_match('/^1\.3\.6\.1\.4\.1\.318\.1\.1\.26\.4\.3\.1\.5(?:\.|$)/', ltrim($oid, '.'))) {
                    $hasRpdu2Power = true;
                    break;
                }
            }
            if (!$hasRpdu2Power) {
                $inject['watts_hundredths_kw'] = '1.3.6.1.4.1.318.1.1.26.4.3.1.5.1';
            }
        }

        $out = [];
        foreach ($inject as $k => $oid) {
            if (!isset($oidMap[$k])) {
                $out[$k] = $oid;
            }
        }
        foreach ($oidMap as $k => $v) {
            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * After collectOidMap: fill missing APC PDU watts from rPDU2 power, legacy
     * rPDU phase/bank current (tenths A), and I×V with inventory voltage.
     * Also reclassifies map values when Discover put rPDULoadStatusLoad under "watts".
     *
     * @param array<string,mixed> $session
     * @param array<string,mixed> $got collectOidMap result
     * @param array<string,mixed> $pdu
     * @param array<string,mixed> $oidMap
     * @return array<string,mixed>
     */
    private static function recoverApcPduLoad(array $session, array $got, array $pdu, array $oidMap): array
    {
        $needW = ($got['watts'] === null || abs((float)$got['watts']) < 0.5);
        $needA = ($got['amps'] === null || abs((float)$got['amps']) < 0.01);
        if (!$needW && !$needA) {
            return $got;
        }

        // --- Mis-map: plain "watts"/"amps" pointing at rPDULoadStatusLoad (tenths A) ---
        $metrics = is_array($got['metrics'] ?? null) ? $got['metrics'] : [];
        foreach ($metrics as $mk => $info) {
            if (!is_array($info)) {
                continue;
            }
            $oid = ltrim((string)($info['oid'] ?? ''), '.');
            $rawN = isset($info['numeric']) && is_numeric($info['numeric'])
                ? (float)$info['numeric'] : null;
            // If key said watts but OID is load-status current, numeric may already be
            // unscaled tenths (or wrongly treated as W). Re-read path from OID.
            if (!preg_match('/^1\.3\.6\.1\.4\.1\.318\.1\.1\.12\.2\.3\.1\.1\.2(?:\.|$)/', $oid)) {
                continue;
            }
            // Prefer raw SNMP value before wrong scale: re-GET
            try {
                $raw = self::get($session, $oid);
                $tenths = self::toNumber($raw);
            } catch (Throwable $e) {
                $tenths = $rawN;
            }
            if ($tenths === null || $tenths < 0) {
                continue;
            }
            $amps = round($tenths / 10.0, 3);
            if ($amps < 0.01) {
                continue;
            }
            // Phase index from trailing .N
            $ph = 1;
            if (preg_match('/\.([123])$/', $oid, $m)) {
                $ph = (int)$m[1];
            }
            $lab = 'L' . $ph;
            if (!is_array($got['phases'] ?? null)) {
                $got['phases'] = [];
            }
            if (!isset($got['phases'][$lab]) || !is_array($got['phases'][$lab])) {
                $got['phases'][$lab] = [
                    'watts' => null, 'amps' => null, 'volts' => null,
                    'va' => null, 'pf' => null, 'peak_amps' => null, 'load_state' => null,
                ];
            }
            if (($got['phases'][$lab]['amps'] ?? null) === null
                || (float)$got['phases'][$lab]['amps'] < 0.01
            ) {
                $got['phases'][$lab]['amps'] = $amps;
            }
            // Drop bogus device watts that were really tenths-of-amps (e.g. 42 → 42 W)
            if ($needW && isset($got['watts']) && abs((float)$got['watts'] - $tenths) < 0.01) {
                $got['watts'] = null;
                $needW = true;
            }
            if (preg_match('/watt|power/', strtolower((string)$mk)) && abs((float)($info['numeric'] ?? 0) - $tenths) < 0.01) {
                // will recompute watts via I×V below
                $needW = true;
            }
        }

        // --- Direct rPDU2 device power ---
        if ($needW) {
            $probed = self::probeApcRpdu2DevicePower($session);
            if ($probed !== null && $probed >= 0.5) {
                $got['watts'] = $probed;
                $needW = false;
            }
        }

        // --- Legacy + rPDU2 phase/bank currents when map had no usable amps ---
        // Always probe classic load-status indexes 1–12 so bank loads are not missed
        // even when phase1–3 map keys already returned 0.
        $phaseAmps = self::probeApcPhaseAmps($session, $got);
        if ($phaseAmps) {
            if (!is_array($got['phases'] ?? null)) {
                $got['phases'] = [];
            }
            foreach ($phaseAmps as $lab => $a) {
                if ($a === null || $a < 0.01) {
                    continue;
                }
                if (!isset($got['phases'][$lab]) || !is_array($got['phases'][$lab])) {
                    $got['phases'][$lab] = [
                        'watts' => null, 'amps' => null, 'volts' => null,
                        'va' => null, 'pf' => null, 'peak_amps' => null, 'load_state' => null,
                    ];
                }
                $cur = $got['phases'][$lab]['amps'] ?? null;
                if ($cur === null || (float)$cur < 0.01) {
                    $got['phases'][$lab]['amps'] = $a;
                }
            }
        }

        // Sum phase amps → device amps
        if ($needA && is_array($got['phases'] ?? null)) {
            $sumA = 0.0;
            $anyA = false;
            foreach (['L1', 'L2', 'L3'] as $lab) {
                $a = $got['phases'][$lab]['amps'] ?? null;
                if ($a !== null && (float)$a > 0) {
                    $sumA += (float)$a;
                    $anyA = true;
                }
            }
            if ($anyA) {
                $got['amps'] = round($sumA, 3);
                $needA = false;
            }
        }

        // Sum phase watts if present
        if ($needW && is_array($got['phases'] ?? null)) {
            $sumW = 0.0;
            $anyW = false;
            foreach (['L1', 'L2', 'L3'] as $lab) {
                $w = $got['phases'][$lab]['watts'] ?? null;
                if ($w !== null && (float)$w >= 0) {
                    $sumW += (float)$w;
                    $anyW = true;
                }
            }
            if ($anyW && $sumW >= 0.5) {
                $got['watts'] = round($sumW, 3);
                $needW = false;
            }
        }

        // Classic AP7xxx L–L / input voltage (Ident 15) — used for power calc on NMC
        $inputV = null;
        try {
            $rawV = self::get($session, '1.3.6.1.4.1.318.1.1.12.1.15.0');
            $inputV = self::toNumber($rawV);
            if ($inputV !== null && $inputV < 1) {
                $inputV = null;
            }
        } catch (Throwable $e) {
            $inputV = null;
        }
        if ($inputV !== null) {
            if (!is_array($got['phases'] ?? null)) {
                $got['phases'] = [];
            }
            if (!isset($got['phases']['L1']) || !is_array($got['phases']['L1'])) {
                $got['phases']['L1'] = [
                    'watts' => null, 'amps' => null, 'volts' => null,
                    'va' => null, 'pf' => null, 'peak_amps' => null, 'load_state' => null,
                ];
            }
            if (($got['phases']['L1']['volts'] ?? null) === null || (float)$got['phases']['L1']['volts'] <= 0) {
                $got['phases']['L1']['volts'] = $inputV;
            }
            // Stash for diagnostics
            if (!isset($got['metrics']['input_volts'])) {
                $got['metrics']['input_volts'] = [
                    'numeric' => $inputV,
                    'raw' => $inputV,
                    'oid' => '1.3.6.1.4.1.318.1.1.12.1.15.0',
                ];
            }
        }

        // I×V with live phase volts, Ident L–L, or inventory nominal
        if ($needW && is_array($got['phases'] ?? null)) {
            $nominal = $inputV;
            if ($nominal === null || $nominal <= 0) {
                $nominal = self::pduNominalVoltsLn($pdu);
            }
            // Default 120 V for single-phase AP7862 when nothing configured
            if ($nominal === null || $nominal <= 0) {
                $nominal = 120.0;
            }
            $pf = 1.0;
            if (isset($got['phases']['_device']['pf']) && is_numeric($got['phases']['_device']['pf'])) {
                $pfv = (float)$got['phases']['_device']['pf'];
                // pf may already be scaled (0–1) or raw thousandths if scale missed
                if ($pfv > 1.5) {
                    $pfv = $pfv / 1000.0;
                }
                if ($pfv > 0.1 && $pfv <= 1.0) {
                    $pf = $pfv;
                }
            }
            $est = 0.0;
            $any = false;
            $phaseCount = 0;
            foreach (['L1', 'L2', 'L3'] as $lab) {
                $a = $got['phases'][$lab]['amps'] ?? null;
                $v = $got['phases'][$lab]['volts'] ?? null;
                if ($a === null || (float)$a <= 0) {
                    continue;
                }
                $phaseCount++;
                $useV = ($v !== null && (float)$v > 0) ? (float)$v : $nominal;
                $est += (float)$a * $useV * $pf;
                $any = true;
            }
            // Single-phase rack: one current × voltage. Multi-phase with only L–L known:
            // if only one phase has amps, simple I×V is correct for AP7862 120V.
            if ($any && $est >= 0.5) {
                $got['watts'] = round($est, 1);
                $needW = false;
            }
        }

        return $got;
    }

    /**
     * Direct GET of rPDU2DeviceStatusPower (.1 then .2) → watts (scaled).
     *
     * @param array<string,mixed> $session
     */
    private static function probeApcRpdu2DevicePower(array $session): ?float
    {
        foreach (['1', '2'] as $idx) {
            $oid = '1.3.6.1.4.1.318.1.1.26.4.3.1.5.' . $idx;
            try {
                $raw = self::get($session, $oid);
                $num = self::toNumber($raw);
                if ($num === null || $num < 0) {
                    continue;
                }
                // hundredths of kW → W
                $w = round($num * 10.0, 3);
                if ($w >= 0.5) {
                    return $w;
                }
            } catch (Throwable $e) {
                // try next index
            }
        }
        return null;
    }

    /**
     * Probe phase/bank current from rPDU2 then legacy rPDULoadStatusLoad (tenths A).
     * Also records every index found under metrics[_apc_load_status_tenths] for diagnostics.
     *
     * @param array<string,mixed> $session
     * @param array<string,mixed>|null $got optional collect result to attach diag
     * @return array<string,float> L1/L2/L3 => amps
     */
    private static function probeApcPhaseAmps(array $session, ?array &$got = null): array
    {
        $out = [];
        $tenthsByIndex = [];

        // rPDU2 phase current (tenths A)
        foreach ([1, 2, 3] as $n) {
            $oid = '1.3.6.1.4.1.318.1.1.26.6.3.1.5.' . $n;
            try {
                $raw = self::get($session, $oid);
                $num = self::toNumber($raw);
                if ($num !== null && $num >= 0) {
                    $tenthsByIndex['rpdu2.' . $n] = $num;
                    $a = round($num / 10.0, 3);
                    if ($a >= 0.01) {
                        $out['L' . $n] = $a;
                    }
                }
            } catch (Throwable $e) {
                // continue
            }
        }

        // Legacy rPDULoadStatusLoad — tenths of Amps; phases then banks (index 1..12)
        $legacySum = 0.0;
        $legacyAny = false;
        for ($n = 1; $n <= 12; $n++) {
            $oid = '1.3.6.1.4.1.318.1.1.12.2.3.1.1.2.' . $n;
            try {
                $raw = self::get($session, $oid);
                $num = self::toNumber($raw);
                if ($num === null || $num < 0) {
                    continue;
                }
                $tenthsByIndex['load.' . $n] = $num;
                $a = round($num / 10.0, 3);
                if ($a < 0.01) {
                    continue;
                }
                if ($n <= 3) {
                    if (!isset($out['L' . $n]) || $out['L' . $n] < 0.01) {
                        $out['L' . $n] = $a;
                    }
                } else {
                    $legacySum += $a;
                    $legacyAny = true;
                }
            } catch (Throwable $e) {
                // noSuchName ends the useful range on most NMCs
                if ($n > 6) {
                    break;
                }
            }
        }
        if (!$out && $legacyAny && $legacySum >= 0.01) {
            $out['L1'] = round($legacySum, 3);
        }

        if ($got !== null && $tenthsByIndex) {
            if (!is_array($got['metrics'] ?? null)) {
                $got['metrics'] = [];
            }
            // Diagnostic only — not used as a map key for totals
            $got['metrics']['_apc_load_status_tenths'] = [
                'numeric' => null,
                'raw' => json_encode($tenthsByIndex),
                'oid' => '1.3.6.1.4.1.318.1.1.12.2.3.1.1.2',
            ];
        }

        return $out;
    }

    /**
     * L–N (or single-phase) nominal volts from PDU inventory for I×V estimates.
     *
     * @param array<string,mixed> $pdu
     */
    private static function pduNominalVoltsLn(array $pdu): ?float
    {
        if (isset($pdu['input_voltage_ln']) && is_numeric($pdu['input_voltage_ln'])) {
            $v = (float)$pdu['input_voltage_ln'];
            return $v > 0 ? $v : null;
        }
        foreach (['input_voltage', 'rated_volts'] as $col) {
            if (!isset($pdu[$col]) || !is_numeric($pdu[$col])) {
                continue;
            }
            $ll = (float)$pdu[$col];
            if ($ll <= 0) {
                continue;
            }
            // 208/240/400/415 L–L → approximate L–N
            if ($ll >= 180) {
                return round($ll / 1.732, 1);
            }
            return $ll;
        }
        return null;
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

        // Custom SNMP thresholds against PDU poll metrics / columns
        if (class_exists('SnmpThresholdService') && $pduId > 0) {
            try {
                $fresh = Database::fetchOne('SELECT * FROM pdus WHERE pdu_id = ?', [$pduId]);
                if ($fresh) {
                    $flat = SnmpThresholdService::metricsFromPollRow('pdu', $fresh);
                    if ($watts !== null) {
                        $flat['watts'] = (float)$watts;
                    }
                    if ($amps !== null) {
                        $flat['amps'] = (float)$amps;
                    }
                    if (is_array($phases)) {
                        $i = 0;
                        foreach ($phases as $lab => $ph) {
                            $i++;
                            if (!is_array($ph)) {
                                continue;
                            }
                            $n = is_numeric($lab) ? ((int)$lab + 1) : $i;
                            if (isset($ph['amps']) && is_numeric($ph['amps'])) {
                                $flat['phase' . $n . '_amps'] = (float)$ph['amps'];
                                $flat[strtolower((string)$lab) . '_amps'] = (float)$ph['amps'];
                            }
                            if (isset($ph['watts']) && is_numeric($ph['watts'])) {
                                $flat['phase' . $n . '_watts'] = (float)$ph['watts'];
                            }
                            if (isset($ph['util_pct']) && is_numeric($ph['util_pct'])) {
                                $flat['phase' . $n . '_util'] = (float)$ph['util_pct'];
                            }
                        }
                    }
                    SnmpThresholdService::evaluateEntity(
                        'pdu',
                        $pduId,
                        (string)($fresh['name'] ?? ('PDU #' . $pduId)),
                        $flat
                    );
                }
            } catch (Throwable $e) {
                App::log('SnmpThreshold pdu: ' . $e->getMessage(), 'warning');
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
                $oidNorm = ltrim($oid, '.');
                $raw = self::get($session, $oidNorm);
                $num = self::toNumber($raw);
                // APC sentinel: -1 = "feature not supported" on many rPDU2 leaves
                if ($num !== null && $num < 0
                    && preg_match('/^1\.3\.6\.1\.4\.1\.318\.1\.1\.26\./', $oidNorm)
                ) {
                    $num = null;
                }
                // Discover sometimes maps rPDULoadStatusLoad (tenths A) as plain "watts"
                if (self::isApcRpduLoadStatusLoadOid($oidNorm)) {
                    if ($num !== null && $num >= 0) {
                        // Always tenths of amps on this leaf — never treat as watts
                        $num = round($num / 10.0, 3);
                        if (preg_match('/watt|power/', $metricKey) && !preg_match('/amp|current/', $metricKey)) {
                            // Re-home under phase amps; do not feed device watts
                            $metricKey = 'phase1_amps';
                            if (preg_match('/\.([123])$/', $oidNorm, $im)) {
                                $metricKey = 'phase' . $im[1] . '_amps';
                            }
                        } elseif (!str_contains($metricKey, 'x10') && preg_match('/amp|current/', $metricKey)) {
                            // already divided; key may have been amps_x10 — undo double ÷ later
                        }
                    }
                } else {
                    $num = self::applyMetricScale($metricKey, $num);
                    // OID-path scale when Discover saved plain "watts"/"amps" without _hundredths_kw / _x10
                    $num = self::applyApcRpdu2OidScale($metricKey, $oidNorm, $num);
                }
                $metrics[$metricKey] = [
                    'numeric' => $num,
                    'raw' => $raw,
                    'oid' => $oidNorm,
                ];
                if ($onMetric) {
                    $onMetric((string)$metric, $raw, $num);
                }

                // String identity metrics (serial / MAC / Dell service tag)
                if (preg_match('/^(serial_no|serial|serialnumber|service_tag|servicetag)\b/', $metricKey)) {
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
                // Dell system model string — kept in metrics; applied to device if model empty
                if (preg_match('/^(system_model|model_name|systemmodel)\b/', $metricKey)) {
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
                    // Prefer first real load; ignore later zeros (e.g. rPDUIdentDevicePowerWatts=0
                    // after rPDU2DeviceStatusPower already reported load on AP78xx).
                    if ($deviceWatts === null
                        || (abs((float)$deviceWatts) < 0.5 && abs((float)$num) >= 0.5)
                    ) {
                        $deviceWatts = $num;
                    }
                } elseif (self::isDeviceTotalAmpsKey($metricKey) && $num !== null) {
                    if ($deviceAmps === null
                        || (abs((float)$deviceAmps) < 0.01 && abs((float)$num) >= 0.01)
                    ) {
                        $deviceAmps = $num;
                    }
                } elseif (preg_match('/^(va|device_va|total_va)\b/', $metricKey) && $num !== null) {
                    $deviceVa = $num;
                } elseif (preg_match('/^(pf|device_pf|power_factor)\b/', $metricKey) && $num !== null) {
                    $devicePf = $num;
                } elseif (preg_match('/^(input_volts|line_volts|ll_volts|device_volts)\b/', $metricKey) && $num !== null) {
                    // rPDUIdentDeviceLinetoLineVoltage — use as L1 volts when phases lack voltage
                    if (!isset($phaseBag[1])) {
                        $phaseBag[1] = $emptyPhase();
                    }
                    if (($phaseBag[1]['volts'] ?? null) === null) {
                        $phaseBag[1]['volts'] = $num;
                    }
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

        // Prefer explicit device totals; else sum phases for zone rollup.
        // Treat near-zero device total as missing when phases report real load (common on AP78xx
        // when rPDUIdentDevicePowerWatts stays 0 without L–L voltage calibration).
        $watts = $deviceWatts;
        $amps = $deviceAmps;
        if (($watts === null || abs((float)$watts) < 0.5) && is_array($phasesOut)) {
            $sum = 0.0;
            $any = false;
            foreach (['L1', 'L2', 'L3'] as $lab) {
                if (isset($phasesOut[$lab]['watts']) && $phasesOut[$lab]['watts'] !== null
                    && (float)$phasesOut[$lab]['watts'] >= 0
                ) {
                    $sum += (float)$phasesOut[$lab]['watts'];
                    $any = true;
                }
            }
            if ($any && $sum >= 0.5) {
                $watts = round($sum, 3);
            }
        }
        // Last resort: Σ(I×V) when power leaves are missing/zero but phase current+voltage exist
        if (($watts === null || abs((float)$watts) < 0.5) && is_array($phasesOut)) {
            $est = 0.0;
            $any = false;
            foreach (['L1', 'L2', 'L3'] as $lab) {
                $a = $phasesOut[$lab]['amps'] ?? null;
                $v = $phasesOut[$lab]['volts'] ?? null;
                if ($a !== null && $v !== null && (float)$a > 0 && (float)$v > 0) {
                    $est += (float)$a * (float)$v;
                    $any = true;
                }
            }
            if ($any && $est >= 0.5) {
                $watts = round($est, 1);
            }
        }
        if (($amps === null || abs((float)$amps) < 0.01) && is_array($phasesOut)) {
            $sum = 0.0;
            $any = false;
            foreach (['L1', 'L2', 'L3'] as $lab) {
                if (isset($phasesOut[$lab]['amps']) && $phasesOut[$lab]['amps'] !== null
                    && (float)$phasesOut[$lab]['amps'] > 0
                ) {
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

    /** PowerNet rPDULoadStatusLoad — tenths of Amps (phases then banks). */
    private static function isApcRpduLoadStatusLoadOid(string $oid): bool
    {
        $oid = ltrim($oid, '.');
        return (bool)preg_match('/^1\.3\.6\.1\.4\.1\.318\.1\.1\.12\.2\.3\.1\.1\.2(?:\.|$)/', $oid);
    }

    /**
     * APC PowerNet rPDU2 scales power in hundredths of kW and current in tenths of A.
     * When site templates map those leaves as plain "watts"/"amps", apply the correct scale
     * from the OID path so AP78xx / AP86xx / AP88xx loads are not under-reported as 0.00 kW.
     */
    private static function applyApcRpdu2OidScale(string $metricKey, string $oid, ?float $num): ?float
    {
        if ($num === null) {
            return null;
        }
        $k = strtolower($metricKey);
        $oid = ltrim($oid, '.');
        $already = str_contains($k, 'hundredths_kw') || str_contains($k, '0_01kw') || str_contains($k, 'centikw')
            || str_contains($k, 'tenths_kw')
            || str_contains($k, 'x10') || str_contains($k, 'x100');

        // Device status: Power(5), PeakPower(6), ApparentPower(16) — hundredths of kW/kVA
        if (preg_match('/^1\.3\.6\.1\.4\.1\.318\.1\.1\.26\.4\.3\.1\.(5|6|16)(?:\.|$)/', $oid)) {
            if ($num < 0) {
                return null;
            }
            if (!$already && preg_match('/watt|power|va/', $k) && !str_contains($k, 'factor')) {
                return round($num * 10.0, 3);
            }
            return $num;
        }
        // Phase status: Current(5) tenths A; Power(7)/ApparentPower(8) hundredths kW/kVA
        if (preg_match('/^1\.3\.6\.1\.4\.1\.318\.1\.1\.26\.6\.3\.1\.5(?:\.|$)/', $oid)) {
            if (!$already && preg_match('/amp|current/', $k)) {
                return round($num / 10.0, 3);
            }
            return $num;
        }
        if (preg_match('/^1\.3\.6\.1\.4\.1\.318\.1\.1\.26\.6\.3\.1\.(7|8)(?:\.|$)/', $oid)) {
            if ($num < 0) {
                return null;
            }
            if (!$already && preg_match('/watt|power|va/', $k) && !str_contains($k, 'factor')) {
                return round($num * 10.0, 3);
            }
            return $num;
        }
        // Outlet metered: current(6) tenths A; power(7) hundredths kW
        if (preg_match('/^1\.3\.6\.1\.4\.1\.318\.1\.1\.26\.9\.4\.3\.1\.6(?:\.|$)/', $oid)) {
            if (!$already && preg_match('/amp|current/', $k)) {
                return round($num / 10.0, 3);
            }
            return $num;
        }
        if (preg_match('/^1\.3\.6\.1\.4\.1\.318\.1\.1\.26\.9\.4\.3\.1\.7(?:\.|$)/', $oid)) {
            if ($num < 0) {
                return null;
            }
            if (!$already && preg_match('/watt|power/', $k)) {
                return round($num * 10.0, 3);
            }
            return $num;
        }
        return $num;
    }

    private static function isDeviceTotalWattsKey(string $key): bool
    {
        if (str_starts_with($key, 'phase') || str_starts_with($key, 'outlet')) {
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

    /**
     * Resolve SNMPv3 security level: prefer explicit setting, else derive from passphrases.
     */
    private static function snmpV3SecurityLevel(string $explicit, string $authPass, string $privPass): string
    {
        $e = strtolower(trim($explicit));
        // Normalize common aliases
        $e = str_replace(['_', ' '], '', $e);
        if (in_array($e, ['authpriv', 'authnopriv', 'noauthnopriv'], true)) {
            if ($e === 'authpriv') {
                return 'authPriv';
            }
            if ($e === 'authnopriv') {
                return 'authNoPriv';
            }
            return 'noAuthNoPriv';
        }
        if ($authPass !== '' && $privPass !== '') {
            return 'authPriv';
        }
        if ($authPass !== '') {
            return 'authNoPriv';
        }
        return 'noAuthNoPriv';
    }

    /**
     * Prefer an IP for SNMP when the host is a DNS name (Windows Net-SNMP can be flaky with FQDNs).
     * Falls back to the original name if resolution fails.
     */
    private static function resolveSnmpHost(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return $host;
        }
        // Already IPv4 / bracketed IPv6
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }
        if (preg_match('/^\[[0-9a-fA-F:]+\]$/', $host)) {
            return $host;
        }
        // Strip accidental scheme
        if (preg_match('#^https?://#i', $host)) {
            $host = (string)preg_replace('#^https?://#i', '', $host);
            $host = rtrim(explode('/', $host, 2)[0], '/');
        }
        $ip = @gethostbyname($host);
        if (is_string($ip) && $ip !== '' && $ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        return $host;
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
        string $context,
        string $secLevel = ''
    ) {
        if (!function_exists('snmp3_get') && !function_exists('snmp2_get') && !class_exists('SNMP')) {
            throw new RuntimeException('PHP SNMP extension not available');
        }

        require_once __DIR__ . '/SnmpDiscover.php';
        $authProto = SnmpDiscover::normalizeSnmpProtocol((string)($authProto ?: 'SHA'), 'auth');
        $privProto = SnmpDiscover::normalizeSnmpProtocol((string)($privProto ?: 'AES'), 'priv');

        $hostPort = $host . ($port !== 161 ? ':' . $port : '');
        // Prefer SNMP class so we can set short timeouts (procedural snmp3_get can hang for minutes)
        $timeoutUsec = 2_000_000; // 2 seconds
        $retries = 1;
        $snmpObj = null;
        $resolvedLevel = self::snmpV3SecurityLevel($secLevel, (string)$authPass, (string)$privPass);
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
                    $snmpObj->setSecurity(
                        $resolvedLevel,
                        $authProto,
                        $authPass ?: '',
                        $privProto,
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
            'secLevel' => $resolvedLevel,
            'timeout_usec' => $timeoutUsec,
            'retries' => $retries,
            'snmp' => $snmpObj,
        ];
    }

    private static function get(array $session, string $oid)
    {
        $oid = ltrim($oid, '.');
        $preferProc = !empty($session['prefer_procedural']);
        $timeout = (int)($session['timeout_usec'] ?? 2_000_000);
        $retries = (int)($session['retries'] ?? 1);
        $host = $session['host'];

        // Path A: PHP SNMP class (unless device poll prefers Discover-style procedural GETs)
        if (!$preferProc && !empty($session['snmp']) && $session['snmp'] instanceof \SNMP) {
            try {
                $result = @$session['snmp']->get($oid);
                if ($result !== false) {
                    return $result;
                }
            } catch (Throwable $e) {
                // fall through to procedural
            }
        }

        // Path B: procedural APIs (same family as SnmpDiscover::snmpGet — works for many iDRACs)
        if (($session['version'] ?? '3') === '3' && function_exists('snmp3_get')) {
            $secLevel = (string)($session['secLevel'] ?? '');
            if ($secLevel === '') {
                $secLevel = self::snmpV3SecurityLevel(
                    '',
                    (string)($session['authPass'] ?? ''),
                    (string)($session['privPass'] ?? '')
                );
            }
            $result = @snmp3_get(
                $host,
                $session['user'],
                $secLevel,
                $session['authProto'] ?: 'SHA',
                $session['authPass'] ?: '',
                $session['privProto'] ?: 'AES',
                $session['privPass'] ?: '',
                $oid,
                $timeout,
                $retries
            );
            if ($result !== false) {
                return $result;
            }
        } else {
            // v1 / v2c — session user holds community string
            $community = (string)($session['user'] ?: 'public');
            $ver = strtolower((string)($session['version'] ?? '2c'));
            $result = false;
            if (($ver === '1' || $ver === 'v1') && function_exists('snmpget')) {
                $result = @snmpget($host, $community, $oid, $timeout, $retries);
            } elseif (function_exists('snmp2_get')) {
                $result = @snmp2_get($host, $community, $oid, $timeout, $retries);
            } elseif (function_exists('snmpget')) {
                $result = @snmpget($host, $community, $oid, $timeout, $retries);
            }
            if ($result !== false) {
                return $result;
            }
        }

        // Path C: if we preferred procedural, try SNMP class once as fallback
        if ($preferProc && !empty($session['snmp']) && $session['snmp'] instanceof \SNMP) {
            try {
                $result = @$session['snmp']->get($oid);
                if ($result !== false) {
                    return $result;
                }
            } catch (Throwable $e) {
                throw new RuntimeException('SNMP GET failed for OID ' . $oid . ': ' . $e->getMessage());
            }
        }

        throw new RuntimeException("SNMP GET failed for OID {$oid}");
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
