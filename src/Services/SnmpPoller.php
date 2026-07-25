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

        if (!empty($t['pdu_id']) && ($got['watts'] !== null || $got['amps'] !== null || $got['phases'] !== null || !empty($got['serial_no']))) {
            self::writePduPoll((int)$t['pdu_id'], $got['watts'], $got['amps'], $got['phases'], $got['serial_no'] ?? null);
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

        self::writePduPoll(
            (int)$pdu['pdu_id'],
            $got['watts'],
            $got['amps'],
            $got['phases'],
            $got['serial_no'] ?? null
        );

        return [
            'watts' => $got['watts'],
            'amps' => $got['amps'],
            'phases' => $got['phases'],
            'serial_no' => $got['serial_no'] ?? null,
            'ok' => $got['ok'],
            'failed' => $got['failed'],
        ];
    }

    /**
     * Persist device-total + optional multi-phase snapshot on a PDU.
     * @param array<string,mixed>|null $phases
     */
    private static function writePduPoll(
        int $pduId,
        ?float $watts,
        ?float $amps,
        ?array $phases,
        ?string $serialNo = null
    ): void {
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

        // Fill empty serial from SNMP (never overwrite a value already set)
        if ($serialNo !== null && $serialNo !== '') {
            require_once __DIR__ . '/SnmpDiscover.php';
            SnmpDiscover::applySerialToPduIfEmpty($pduId, $serialNo);
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
                PowerHistoryService::recordSample($pduId, $watts, $amps, $phases, $nominalLn);
            } catch (Throwable $e) {
                // Fallback: legacy minimal insert
                if ($watts !== null || $amps !== null) {
                    try {
                        Database::insert('pdu_readings', [
                            'pdu_id' => $pduId,
                            'watts' => $watts,
                            'amps' => $amps,
                        ]);
                    } catch (Throwable $e2) {
                        // ignore
                    }
                }
            }
        } elseif ($watts !== null || $amps !== null) {
            Database::insert('pdu_readings', [
                'pdu_id' => $pduId,
                'watts' => $watts,
                'amps' => $amps,
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
     * Scales: see applyMetricScale().
     *
     * @param callable|null $onMetric function(string $metric, mixed $raw, ?float $num): void
     * @return array{watts:?float,amps:?float,phases:?array,serial_no:?string,ok:int,failed:int,last_error:?string}
     */
    private static function collectOidMap($session, array $oidMap, ?callable $onMetric = null): array
    {
        $deviceWatts = null;
        $deviceAmps = null;
        $deviceVa = null;
        $devicePf = null;
        $ratedAmps = null;
        $serialNo = null;
        $ps1 = null;
        $ps2 = null;
        $psAlarm = null;
        $ll = [];
        /** @var array<int,array<string,?float>> $phaseBag */
        $phaseBag = [];
        $ok = 0;
        $failed = 0;
        $lastErr = null;

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
            try {
                $raw = self::get($session, ltrim($oid, '.'));
                $num = self::toNumber($raw);
                $metricKey = strtolower(trim((string)$metric));
                $num = self::applyMetricScale($metricKey, $num);
                if ($onMetric) {
                    $onMetric((string)$metric, $raw, $num);
                }

                // String identity metrics (serial)
                if (preg_match('/^(serial_no|serial|serialnumber)\b/', $metricKey)) {
                    require_once __DIR__ . '/SnmpDiscover.php';
                    $serialNo = SnmpDiscover::cleanSerialValue($raw) ?? $serialNo;
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
            'serial_no' => $serialNo,
            'ok' => $ok,
            'failed' => $failed,
            'last_error' => $lastErr,
        ];
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
