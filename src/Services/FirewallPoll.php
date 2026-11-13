<?php
/**
 * Cisco ASA stats over SNMP (CISCO-FIREWALL-MIB, IF-MIB, HOST resources).
 * Does not collect license activation keys.
 */
declare(strict_types=1);

class FirewallPoll
{
    /** NAPT / PAT port binds. The port-bind table itself is not walked. */
    public const OID_XLATE_PAT = '1.3.6.1.4.1.9.10.77.1.2.3.0';
    /** One-to-one address binds. Not the PAT table. */
    public const OID_XLATE_NAT = '1.3.6.1.4.1.9.10.77.1.2.1.0';
    /** Active address translations. Used only when the port-bind scalar is missing. */
    public const OID_XLATE_ACTIVE = '1.3.6.1.4.1.9.9.532.1.1.1.1';
    public const OID_VPN_SESSIONS = '1.3.6.1.4.1.9.9.392.1.3.1.0';
    public const OID_VPN_IPSEC = '1.3.6.1.4.1.9.9.392.1.3.26.0';
    public const OID_VPN_L2L = '1.3.6.1.4.1.9.9.392.1.3.29.0';
    public const OID_VPN_SVC = '1.3.6.1.4.1.9.9.392.1.3.35.0';
    public const OID_VPN_WEBVPN = '1.3.6.1.4.1.9.9.392.1.3.38.0';
    public const OID_VPN_PEAK = '1.3.6.1.4.1.9.9.392.1.3.41.0';
    public const OID_IKE_TUNNELS = '1.3.6.1.4.1.9.9.171.1.2.1.1.0';
    public const OID_IPSEC_TUNNELS = '1.3.6.1.4.1.9.9.171.1.3.1.1.0';
    /** ifName rows kept per poll. Tunnel nameifs follow the physical ports, so a short cap drops them. */
    private const INTERFACE_KEEP = 128;
    /** cpmCPUTotal5minRev, then 1minRev, then the deprecated 5min and 1min columns. */
    private const CPU_LOAD_OIDS = [
        '1.3.6.1.4.1.9.9.109.1.1.1.1.8',
        '1.3.6.1.4.1.9.9.109.1.1.1.1.7',
        '1.3.6.1.4.1.9.9.109.1.1.1.1.5',
        '1.3.6.1.4.1.9.9.109.1.1.1.1.4',
    ];

    /**
     * @param array<string,mixed> $fw firewalls row
     * @return array<string,mixed> snapshot stored in last_poll_json
     */
    public static function poll(array $fw): array
    {
        require_once __DIR__ . '/SnmpPoller.php';
        require_once __DIR__ . '/SnmpDiscover.php';
        require_once __DIR__ . '/Crypto.php';

        $host = trim((string)($fw['primary_ip'] ?? ''));
        if ($host === '') {
            throw new RuntimeException('Firewall has no management IP for SNMP.');
        }
        $ver = strtolower(trim((string)($fw['snmp_version'] ?? '2c')));
        if ($ver === 'v3') {
            $ver = '3';
        }
        if ($ver === '2' || $ver === 'v2c' || $ver === '') {
            $ver = '2c';
        }
        if ($ver === 'v1') {
            $ver = '1';
        }
        $communityOrUser = ($ver === '3')
            ? (string)($fw['snmp_security_name'] ?? '')
            : (string)(Crypto::decryptQuiet($fw['snmp_community'] ?? null) ?? '');
        if ($communityOrUser === '') {
            throw new RuntimeException($ver === '3'
                ? 'SNMPv3 security name is required.'
                : 'SNMP community is required.');
        }
        $authProto = SnmpDiscover::normalizeSnmpProtocol((string)($fw['snmp_auth_protocol'] ?? 'SHA'), 'auth');
        $privProto = SnmpDiscover::normalizeSnmpProtocol((string)($fw['snmp_priv_protocol'] ?? 'AES'), 'priv');
        $authPass = (string)(Crypto::decryptQuiet($fw['snmp_auth_passphrase'] ?? null) ?? '');
        $privPass = (string)(Crypto::decryptQuiet($fw['snmp_priv_passphrase'] ?? null) ?? '');
        $secLevel = SnmpDiscover::resolveSecLevel([
            'security_level' => (string)($fw['snmp_v3_sec_level'] ?? ''),
            'auth_passphrase' => $authPass,
            'priv_passphrase' => $privPass,
        ]);
        $pollHost = SnmpPoller::resolveSnmpHostPublic($host);
        $platform = self::platformOf($fw);
        $snapshot = null;
        $snmpError = null;
        try {
            $session = SnmpPoller::openSessionPublic(
                $pollHost,
                (int)($fw['snmp_port'] ?? 161) ?: 161,
                $ver,
                $communityOrUser,
                $authProto,
                $authPass,
                $privProto,
                $privPass,
                (string)($fw['snmp_context'] ?? ''),
                $secLevel,
                (string)($fw['snmp_engine_id'] ?? '')
            );
            try {
                $snapshot = $platform === 'ftd' ? self::collectFtd($session) : self::collect($session);
            } finally {
                SnmpPoller::closeSessionPublic($session);
            }
        } catch (Throwable $e) {
            $snmpError = $e->getMessage();
        }
        if (!is_array($snapshot)) {
            $snapshot = ['platform' => $platform];
        }
        $snapshot['platform'] = $platform;
        // Judge the device poll before the management center fills blanks.
        $snmpUseful = $snmpError === null && self::snapshotHasCounters($snapshot);
        if ($platform === 'ftd') {
            require_once __DIR__ . '/FmcClient.php';
            $snapshot = FmcClient::enrich($fw, $snapshot);
        }
        if ($snmpError === null && !$snmpUseful && $platform === 'ftd') {
            $snmpError = self::snmpLibraryBlock($authProto, $privProto)
                ?? 'SNMP returned no Threat Defense hostname, uptime, or counters.';
        }
        $fmcUseful = trim((string)($snapshot['fmc_health'] ?? '')) !== ''
            || trim((string)($snapshot['ha_pair'] ?? '')) !== ''
            || !empty($snapshot['fmc_alerts']);
        if (!$snmpUseful && !$fmcUseful) {
            $message = $snmpError ?? 'SNMP answered no firewall counters.';
            self::recordError($fw, $message);
            throw new RuntimeException($message);
        }
        self::save($fw, $snapshot);
        if ($snmpError !== null) {
            self::recordError($fw, $snmpError);
        }
        return $snapshot;
    }

    public static function platformOf(array $fw): string
    {
        $platform = strtolower(trim((string)($fw['platform'] ?? 'asa')));
        return $platform === 'ftd' ? 'ftd' : 'asa';
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public static function collect(array $session): array
    {
        $descr = self::text(self::tryGet($session, '1.3.6.1.2.1.1.1.0'));
        $name = self::text(self::tryGet($session, '1.3.6.1.2.1.1.5.0'));
        $uptime = self::parseUptimeSec(self::tryGet($session, '1.3.6.1.2.1.1.3.0'));
        if ($uptime === null) {
            $uptime = self::parseUptimeSec(self::tryGet($session, '1.3.6.1.2.1.25.1.1.0'));
        }
        $ident = self::parseSysDescr($descr);
        $cpu = null;
        foreach (self::CPU_LOAD_OIDS as $cpuOid) {
            $cpu = self::firstNumber(self::reindex(SnmpPoller::sessionWalk($session, $cpuOid), $cpuOid));
            if ($cpu !== null) {
                break;
            }
        }
        $memNames = '1.3.6.1.4.1.9.9.48.1.1.1.2';
        $memUsed = '1.3.6.1.4.1.9.9.48.1.1.1.5';
        $memFree = '1.3.6.1.4.1.9.9.48.1.1.1.6';
        $mem = self::memory(
            self::reindex(SnmpPoller::sessionWalk($session, $memNames), $memNames),
            self::reindex(SnmpPoller::sessionWalk($session, $memUsed), $memUsed),
            self::reindex(SnmpPoller::sessionWalk($session, $memFree), $memFree)
        );
        if ($mem['pct'] === null) {
            $mem = self::enhancedMemory($session);
        }
        if ($mem['pct'] === null) {
            $hostMem = self::hostMemory($session);
            if ($hostMem['pct'] !== null) {
                $mem = $hostMem;
            }
        }
        $hwInfoOid = '1.3.6.1.4.1.9.9.147.1.2.1.1.1.2';
        $hwDetailOid = '1.3.6.1.4.1.9.9.147.1.2.1.1.1.4';
        $hwValueOid = '1.3.6.1.4.1.9.9.147.1.2.1.1.1.3';
        $hwInfo = self::walkText(self::reindex(SnmpPoller::sessionWalk($session, $hwInfoOid), $hwInfoOid));
        $hwDetail = self::walkText(self::reindex(SnmpPoller::sessionWalk($session, $hwDetailOid), $hwDetailOid));
        $hwValue = self::reindex(SnmpPoller::sessionWalk($session, $hwValueOid), $hwValueOid);
        $failover = self::summarizeHardware($hwInfo, $hwDetail, $hwValue);
        $connDescOid = '1.3.6.1.4.1.9.9.147.1.2.2.2.1.3';
        $connValOid = '1.3.6.1.4.1.9.9.147.1.2.2.2.1.5';
        $connDesc = self::walkText(self::reindex(SnmpPoller::sessionWalk($session, $connDescOid), $connDescOid));
        $connVal = self::reindex(SnmpPoller::sessionWalk($session, $connValOid), $connValOid);
        $connections = self::pickConnections($connDesc, $connVal);
        $connPeak = self::pickConnectionPeak($connDesc, $connVal);
        $cps = self::numeric(self::tryGet($session, '1.3.6.1.4.1.9.9.147.1.2.2.3.0'));
        $cpsPeak = self::numeric(self::tryGet($session, '1.3.6.1.4.1.9.9.147.1.2.2.4.0'));
        $xlatePat = self::gauge(self::tryGet($session, self::OID_XLATE_PAT));
        $xlateNat = self::gauge(self::tryGet($session, self::OID_XLATE_NAT));
        $xlateSource = $xlatePat !== null ? 'port' : null;
        if ($xlatePat === null) {
            $xlatePat = self::translationActive($session);
            $xlateSource = $xlatePat !== null ? 'active' : null;
        }
        if ($ident['model'] === null) {
            $ident['model'] = self::modelFromEntities($session);
        }
        $serial = self::chassisSerial($session);
        $interfaces = self::interfaces($session);

        if ($descr === '' && $name === '' && !$interfaces && $connections === null) {
            throw new RuntimeException('SNMP answered no ASA system, interface, or firewall counters.');
        }

        return [
            'polled_at' => gmdate('c'),
            'sys_name' => $name,
            'sys_descr' => $descr,
            'version' => $ident['version'],
            'model_guess' => $ident['model'],
            'uptime_sec' => $uptime,
            'cpu_pct' => $cpu,
            'memory' => $mem,
            'failover' => $failover,
            'connections' => $connections,
            'conn_peak' => $connPeak,
            'connections_per_sec' => $cps !== null ? (int)round($cps) : null,
            'connections_per_sec_peak' => $cpsPeak !== null ? (int)round($cpsPeak) : null,
            'xlate_pat' => $xlatePat,
            'xlate_nat' => $xlateNat,
            'xlate_source' => $xlateSource,
            // Counts only. The remote-access session table is not read.
            'vpn_sessions' => self::gauge(self::tryGet($session, self::OID_VPN_SESSIONS)),
            'vpn_peak' => self::gauge(self::tryGet($session, self::OID_VPN_PEAK)),
            'vpn_ipsec' => self::gauge(self::tryGet($session, self::OID_VPN_IPSEC)),
            'vpn_svc' => self::gauge(self::tryGet($session, self::OID_VPN_SVC)),
            'vpn_webvpn' => self::gauge(self::tryGet($session, self::OID_VPN_WEBVPN)),
            'vpn_l2l' => self::gauge(self::tryGet($session, self::OID_VPN_L2L)),
            'ipsec_tunnels' => self::gauge(self::tryGet($session, self::OID_IPSEC_TUNNELS)),
            'ike_tunnels' => self::gauge(self::tryGet($session, self::OID_IKE_TUNNELS)),
            'serial' => $serial,
            'interfaces' => $interfaces,
        ];
    }

    /**
     * @return array{version:?string,model:?string}
     */
    public static function parseSysDescr(string $descr): array
    {
        $version = null;
        $model = null;
        if (preg_match('/Version\s+([0-9][0-9().]+)/i', $descr, $m)) {
            $version = $m[1];
        }
        if (preg_match('/\bASA\s*([0-9]{4}[0-9A-Z-]*)\b/i', $descr, $m)) {
            $model = 'ASA' . strtoupper($m[1]);
        } elseif (preg_match('/\b(ASA[0-9]{4}[0-9A-Z-]*)\b/i', $descr, $m)) {
            $model = strtoupper($m[1]);
        } elseif (preg_match('/\b(?:Secure Firewall|Firepower|FPR-?)\s*([0-9]{3,4}[0-9A-Z-]*)\b/i', $descr, $m)) {
            $model = 'Secure Firewall ' . strtoupper($m[1]);
        }
        return ['version' => $version, 'model' => $model];
    }

    /**
     * Threat Defense on the device management address. ASA counters are read when LINA still answers them.
     *
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public static function collectFtd(array $session): array
    {
        try {
            $base = self::collect($session);
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'no ASA system')) {
                throw $e;
            }
            $base = [
                'polled_at' => gmdate('c'),
                'sys_name' => '',
                'sys_descr' => '',
                'version' => null,
                'model_guess' => null,
                'uptime_sec' => null,
                'cpu_pct' => null,
                'memory' => null,
                'failover' => ['this_unit' => null, 'peer' => null, 'rows' => []],
                'connections' => null,
                'conn_peak' => null,
                'connections_per_sec' => null,
                'connections_per_sec_peak' => null,
                'xlate_pat' => null,
                'xlate_nat' => null,
                'xlate_source' => null,
                'vpn_sessions' => null,
                'vpn_peak' => null,
                'vpn_ipsec' => null,
                'vpn_svc' => null,
                'vpn_webvpn' => null,
                'vpn_l2l' => null,
                'ipsec_tunnels' => null,
                'ike_tunnels' => null,
                'serial' => null,
                'interfaces' => [],
            ];
        }
        $cpus = self::classifyCpus(self::cpuRows($session));
        if ($cpus['cpu_pct'] !== null) {
            $base['cpu_pct'] = $cpus['cpu_pct'];
        }
        $base['cpu_lina_pct'] = $cpus['cpu_lina_pct'];
        $base['cpu_snort_pct'] = $cpus['cpu_snort_pct'];
        $base['disk_pct'] = self::pickDisk(self::diskRows($session));
        $active = self::gauge(self::tryGet($session, '1.3.6.1.4.1.9.9.491.1.1.1.6.0'));
        if ($active !== null) {
            $base['connections'] = $active;
        }
        $half = self::gauge(self::tryGet($session, '1.3.6.1.4.1.9.9.491.1.1.1.5.0'));
        if ($half !== null) {
            $base['conn_half_open'] = $half;
        }
        $rate = self::gauge(self::tryGet($session, '1.3.6.1.4.1.9.9.491.1.1.1.10.0'));
        if ($rate !== null) {
            $base['connections_per_sec'] = $rate;
        }
        $remote = self::gauge(self::tryGet($session, '1.3.6.1.4.1.9.9.491.1.1.1.12.0'));
        if ($remote !== null) {
            $base['remote_access_total'] = $remote;
        }
        $sensors = self::sensorRows($session);
        if ($sensors) {
            $fail = is_array($base['failover'] ?? null) ? $base['failover'] : [
                'this_unit' => null,
                'peer' => null,
                'rows' => [],
            ];
            $rows = is_array($fail['rows'] ?? null) ? $fail['rows'] : [];
            $fail['rows'] = array_merge($rows, $sensors);
            $base['failover'] = $fail;
        }
        $fail = is_array($base['failover'] ?? null) ? $base['failover'] : [
            'this_unit' => null,
            'peer' => null,
            'rows' => [],
        ];
        if (empty($fail['this_unit'])) {
            $haOid = '1.3.6.1.4.1.9.9.491.1.4.2.1.1.3';
            $role = self::firstHaRole(self::reindex(SnmpPoller::sessionWalk($session, $haOid), $haOid));
            if ($role !== null) {
                $fail['this_unit'] = $role;
                $fail['peer'] = $fail['peer'] ?? null;
                $fail['rows'] = is_array($fail['rows'] ?? null) ? $fail['rows'] : [];
                $base['failover'] = $fail;
            }
        }
        $base['platform'] = 'ftd';
        return $base;
    }

    /**
     * @param list<array{name:string,value:float}> $rows
     * @return array{cpu_pct:?float,cpu_lina_pct:?float,cpu_snort_pct:?float}
     */
    public static function classifyCpus(array $rows): array
    {
        $lina = null;
        $snort = null;
        $system = null;
        $plain = [];
        foreach ($rows as $row) {
            $value = $row['value'];
            $label = strtolower($row['name']);
            if (str_contains($label, 'snort')) {
                $snort = $value;
            } elseif (str_contains($label, 'lina') || str_contains($label, 'data plane') || str_contains($label, 'dataplane') || str_contains($label, 'data-plane') || str_contains($label, 'data path')) {
                $lina = $value;
            } elseif (str_contains($label, 'system') || str_contains($label, 'total') || $label === 'cpu') {
                $system = $value;
            } else {
                $plain[] = $value;
            }
        }
        if ($system === null && $plain) {
            $system = round(array_sum($plain) / count($plain), 2);
        }
        return [
            'cpu_pct' => $system,
            'cpu_lina_pct' => $lina,
            'cpu_snort_pct' => $snort,
        ];
    }

    /**
     * @param list<array{label:string,size:float,used:float}> $rows
     */
    public static function pickDisk(array $rows): ?float
    {
        $best = null;
        $bestSize = -1.0;
        foreach ($rows as $row) {
            $label = strtolower($row['label']);
            $type = strtolower((string)($row['type'] ?? ''));
            if (self::storageSkip($label, $type) || !self::storageKeep($label, $type)) {
                continue;
            }
            if ($row['size'] <= 0) {
                continue;
            }
            if ($row['size'] > $bestSize) {
                $bestSize = $row['size'];
                $best = round(($row['used'] / $row['size']) * 100, 2);
            }
        }
        return $best;
    }

    /**
     * @param array<string,string> $info
     * @param array<string,string> $detail
     * @param array<string,mixed> $value
     * @return array{this_unit:?string,peer:?string,rows:list<array{label:string,detail:string,status:string}>}
     */
    public static function summarizeHardware(array $info, array $detail, array $value): array
    {
        $rows = [];
        $thisUnit = null;
        $peer = null;
        $keys = array_unique(array_merge(array_keys($info), array_keys($detail), array_keys($value)));
        foreach ($keys as $k) {
            $label = trim((string)($info[$k] ?? ''));
            $det = trim((string)($detail[$k] ?? ''));
            if ($label === '' && $det === '') {
                continue;
            }
            $code = self::numeric($value[$k] ?? null);
            $status = self::hardwareStatus($code, $det);
            $rows[] = ['label' => $label, 'detail' => $det, 'status' => $status];
            $blob = strtolower($label . ' ' . $det);
            $thisHost = str_contains($blob, 'this device') || str_contains($blob, 'this host') || str_contains($blob, 'this unit');
            $otherHost = str_contains($blob, 'other host') || str_contains($blob, 'other device') || str_contains($blob, 'other unit');
            if ($thisHost && !$otherHost) {
                $thisUnit = $status !== '' ? $status : $det;
            } elseif ($otherHost || ($peer === null && (str_contains($blob, 'secondary') || str_contains($blob, 'primary')))) {
                $peer = $status !== '' ? $status : $det;
            }
        }
        return ['this_unit' => $thisUnit, 'peer' => $peer, 'rows' => $rows];
    }

    /**
     * Power, fan, and temperature rows that are not in a normal state.
     * Failover role rows are left out. Healthy supplies and fans are left out.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array{label:string,detail:string,status:string}>
     */
    public static function hardwareAlerts(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string)($row['label'] ?? ''));
            $detail = trim((string)($row['detail'] ?? ''));
            $status = trim((string)($row['status'] ?? ''));
            $blob = strtolower($label . ' ' . $detail);
            if (!preg_match('/power|psu|fan|temp|thermal|voltage/', $blob)) {
                continue;
            }
            if ($status === '' || self::hardwareHealthy($status)) {
                continue;
            }
            $out[] = ['label' => $label, 'detail' => $detail, 'status' => $status];
            if (count($out) >= 8) {
                break;
            }
        }
        return $out;
    }

    private static function hardwareHealthy(string $status): bool
    {
        $s = strtolower(trim($status));
        if (in_array($s, ['up', 'active', 'ok', 'normal', 'standby', 'backup', 'other'], true)) {
            return true;
        }
        return str_contains($s, 'normal') || preg_match('/\b(ok|good)\b/', $s) === 1;
    }

    /**
     * @param array<string,string> $desc
     * @param array<string,mixed> $values
     */
    public static function pickConnections(array $desc, array $values): ?int
    {
        $best = null;
        $bestScore = -1;
        $sawInUse = false;
        foreach ($values as $suffix => $raw) {
            $n = self::numeric($raw);
            if ($n === null) {
                continue;
            }
            $suffix = (string)$suffix;
            $inUse = str_ends_with($suffix, '.6') || $suffix === '6';
            if (!$inUse) {
                continue;
            }
            $sawInUse = true;
            $text = strtolower((string)($desc[$suffix] ?? ''));
            $score = 2;
            if ($text !== '' && (str_contains($text, 'in use') || str_contains($text, 'currently'))) {
                $score += 5;
            }
            if ($text !== '' && (str_contains($text, 'entire') || str_contains($text, 'firewall') || str_contains($text, 'global'))) {
                $score += 3;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (int)round($n);
            }
        }
        if ($sawInUse) {
            return $best;
        }
        $best = null;
        $bestScore = -1;
        foreach ($values as $suffix => $raw) {
            $n = self::numeric($raw);
            if ($n === null) {
                continue;
            }
            $text = strtolower((string)($desc[(string)$suffix] ?? ''));
            if ($text === '' || str_contains($text, 'peak') || str_contains($text, 'highest') || str_contains($text, 'per second')) {
                continue;
            }
            if (!str_contains($text, 'in use') && !str_contains($text, 'currently')) {
                continue;
            }
            $score = 2;
            if (str_contains($text, 'entire') || str_contains($text, 'firewall') || str_contains($text, 'global')) {
                $score += 3;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (int)round($n);
            }
        }
        return $best;
    }

    /**
     * Connection-table high-water mark from the description text.
     * The stat type number is not assumed. Per-second peaks are ignored.
     *
     * @param array<string,string> $desc
     * @param array<string,mixed> $values
     */
    public static function pickConnectionPeak(array $desc, array $values): ?int
    {
        $best = null;
        $bestScore = -1;
        $saw = false;
        foreach ($values as $suffix => $raw) {
            $n = self::numeric($raw);
            if ($n === null) {
                continue;
            }
            $text = strtolower((string)($desc[$suffix] ?? ''));
            if ($text === '' || str_contains($text, 'per second') || str_contains($text, 'per-second')) {
                continue;
            }
            $peakish = str_contains($text, 'peak')
                || str_contains($text, 'highest')
                || str_contains($text, 'maximum')
                || preg_match('/\bmost\b/', $text) === 1;
            if (!$peakish) {
                continue;
            }
            $saw = true;
            $score = 2;
            if (str_contains($text, 'entire') || str_contains($text, 'firewall') || str_contains($text, 'global')) {
                $score += 3;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (int)round($n);
            }
        }
        return $saw ? $best : null;
    }

    /**
     * @param array<string,mixed> $fw
     * @param array<string,mixed> $snapshot
     */
    private static function save(array $fw, array $snapshot): void
    {
        $id = (int)($fw['firewall_id'] ?? 0);
        if ($id < 1) {
            return;
        }
        $failover = is_array($snapshot['failover'] ?? null) ? $snapshot['failover'] : [];
        $patch = [
            // UTC digits. The scheduler must use isDueUtc(), not isDue().
            'snmp_last_poll_at' => gmdate('Y-m-d H:i:s'),
            'last_poll_json' => json_encode($snapshot, JSON_UNESCAPED_SLASHES),
            'last_error' => null,
            'last_version' => $snapshot['version'] ?? null,
            'last_failover' => isset($failover['this_unit']) ? (string)$failover['this_unit'] : null,
            'last_cpu_pct' => isset($snapshot['cpu_pct']) && is_numeric($snapshot['cpu_pct'])
                ? round((float)$snapshot['cpu_pct'], 2) : null,
            'last_connections' => isset($snapshot['connections']) ? (int)$snapshot['connections'] : null,
            'last_cps' => isset($snapshot['connections_per_sec']) ? (int)$snapshot['connections_per_sec'] : null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        if (trim((string)($fw['hostname'] ?? '')) === '' && !empty($snapshot['sys_name'])) {
            $patch['hostname'] = (string)$snapshot['sys_name'];
        }
        if (trim((string)($fw['model'] ?? '')) === '' && !empty($snapshot['model_guess'])) {
            $patch['model'] = (string)$snapshot['model_guess'];
        }
        if (trim((string)($fw['serial_no'] ?? '')) === '' && !empty($snapshot['serial'])) {
            $patch['serial_no'] = (string)$snapshot['serial'];
        }
        Database::update('firewalls', $patch, 'firewall_id = :id', [':id' => $id]);
        self::recordSample($fw, $snapshot, (string)$patch['snmp_last_poll_at']);
    }

    /**
     * Keep a short history so utilization is the change since the previous poll.
     *
     * @param array<string,mixed> $fw
     * @param array<string,mixed> $snapshot
     */
    private static function recordSample(array $fw, array $snapshot, string $polledAt): void
    {
        $id = (int)($fw['firewall_id'] ?? 0);
        if ($id < 1) {
            return;
        }
        try {
            self::insertSampleRows($fw, $snapshot, $polledAt);
        } catch (Throwable $e) {
            try {
                require_once dirname(__DIR__) . '/Schema.php';
                Schema::ensureFirewalls();
                self::insertSampleRows($fw, $snapshot, $polledAt);
            } catch (Throwable $e2) {
                // The live snapshot is already saved. Charts wait until a later poll can insert.
                if (class_exists('App')) {
                    App::log('Firewall sample was not stored: ' . $e2->getMessage(), 'warning');
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $fw
     * @param array<string,mixed> $snapshot
     */
    private static function insertSampleRows(array $fw, array $snapshot, string $polledAt): void
    {
        $id = (int)$fw['firewall_id'];
        $existing = (int)Database::fetchValue(
            'SELECT COUNT(*) FROM firewall_samples WHERE firewall_id = ?',
            [$id]
        );
        if ($existing === 0 && !empty($fw['last_poll_json'])) {
            $old = json_decode((string)$fw['last_poll_json'], true);
            $oldAt = is_array($old) ? strtotime((string)($old['polled_at'] ?? '')) : false;
            $newAt = strtotime($polledAt . ' UTC');
            if (is_array($old) && $oldAt !== false && $newAt !== false && abs($newAt - $oldAt) >= 20) {
                self::writeSample($id, $old, gmdate('Y-m-d H:i:s', $oldAt));
            }
        }
        self::writeSample($id, $snapshot, $polledAt);
        Database::query(
            'DELETE FROM firewall_samples WHERE firewall_id = ? AND polled_at < DATEADD(day, -14, SYSUTCDATETIME())',
            [$id]
        );
        Database::query(
            'DELETE FROM firewall_if_samples WHERE firewall_id = ? AND polled_at < DATEADD(day, -14, SYSUTCDATETIME())',
            [$id]
        );
    }

    /** @param array<string,mixed> $snapshot */
    private static function writeSample(int $id, array $snapshot, string $polledAt): void
    {
        $mem = is_array($snapshot['memory'] ?? null) ? $snapshot['memory'] : [];
        $fail = is_array($snapshot['failover'] ?? null) ? $snapshot['failover'] : [];
        Database::insert('firewall_samples', [
            'firewall_id' => $id,
            'cpu_pct' => isset($snapshot['cpu_pct']) && is_numeric($snapshot['cpu_pct'])
                ? round((float)$snapshot['cpu_pct'], 2) : null,
            'memory_pct' => isset($mem['pct']) && is_numeric($mem['pct']) ? round((float)$mem['pct'], 2) : null,
            'connections' => isset($snapshot['connections']) && $snapshot['connections'] !== null
                ? (int)$snapshot['connections'] : null,
            'cps' => isset($snapshot['connections_per_sec']) && $snapshot['connections_per_sec'] !== null
                ? (int)$snapshot['connections_per_sec'] : null,
            'conn_peak' => self::sampleInt($snapshot, 'conn_peak'),
            'xlate_pat' => self::sampleInt($snapshot, 'xlate_pat'),
            'xlate_nat' => self::sampleInt($snapshot, 'xlate_nat'),
            'vpn_sessions' => self::sampleInt($snapshot, 'vpn_sessions'),
            'vpn_peak' => self::sampleInt($snapshot, 'vpn_peak'),
            'vpn_ipsec' => self::sampleInt($snapshot, 'vpn_ipsec'),
            'vpn_svc' => self::sampleInt($snapshot, 'vpn_svc'),
            'vpn_webvpn' => self::sampleInt($snapshot, 'vpn_webvpn'),
            'vpn_l2l' => self::sampleInt($snapshot, 'vpn_l2l'),
            'ipsec_tunnels' => self::sampleInt($snapshot, 'ipsec_tunnels'),
            'ike_tunnels' => self::sampleInt($snapshot, 'ike_tunnels'),
            'cpu_lina_pct' => self::samplePct($snapshot, 'cpu_lina_pct'),
            'cpu_snort_pct' => self::samplePct($snapshot, 'cpu_snort_pct'),
            'disk_pct' => self::samplePct($snapshot, 'disk_pct'),
            'failover' => isset($fail['this_unit']) && $fail['this_unit'] !== null && $fail['this_unit'] !== ''
                ? substr((string)$fail['this_unit'], 0, 40) : null,
            'polled_at' => $polledAt,
        ]);
        $ifaces = is_array($snapshot['interfaces'] ?? null) ? $snapshot['interfaces'] : [];
        foreach ($ifaces as $iface) {
            if (!is_array($iface)) {
                continue;
            }
            $name = trim((string)($iface['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            Database::insert('firewall_if_samples', [
                'firewall_id' => $id,
                'if_name' => substr($name, 0, 128),
                'oper' => substr((string)($iface['oper'] ?? ''), 0, 20) ?: null,
                'speed_mbps' => isset($iface['speed_mbps']) && is_numeric($iface['speed_mbps'])
                    ? (int)$iface['speed_mbps'] : null,
                'in_octets' => self::octetString($iface['in_octets'] ?? null),
                'out_octets' => self::octetString($iface['out_octets'] ?? null),
                'in_errors' => self::octetString($iface['in_errors'] ?? null),
                'out_errors' => self::octetString($iface['out_errors'] ?? null),
                'in_discards' => self::octetString($iface['in_discards'] ?? null),
                'out_discards' => self::octetString($iface['out_discards'] ?? null),
                'polled_at' => $polledAt,
            ]);
        }
    }

    /** @param mixed $raw */
    private static function octetString($raw): ?string
    {
        if (is_int($raw)) {
            return $raw >= 0 ? (string)$raw : null;
        }
        if (is_float($raw) && is_finite($raw) && $raw >= 0) {
            return sprintf('%.0f', $raw);
        }
        if (is_string($raw)) {
            $s = trim($raw);
            if (preg_match('/^\d+$/', $s)) {
                return $s;
            }
            if (preg_match('/^(?:Counter64|Counter32|Gauge32|Unsigned32|INTEGER|Integer32)\s*:\s*(\d+)\s*$/i', $s, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    /** @param array<string,mixed> $fw */
    private static function recordError(array $fw, string $message): void
    {
        $id = (int)($fw['firewall_id'] ?? 0);
        if ($id < 1) {
            return;
        }
        try {
            Database::update('firewalls', [
                'last_error' => substr($message, 0, 400),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], 'firewall_id = :id', [':id' => $id]);
        } catch (Throwable $e) {
            // Keep the SNMP error as the one the caller sees.
        }
    }

    /** @param array<string,mixed> $session */
    private static function interfaces(array $session): array
    {
        $nameOid = '1.3.6.1.2.1.31.1.1.1.1';
        $operOid = '1.3.6.1.2.1.2.2.1.8';
        $speedOid = '1.3.6.1.2.1.31.1.1.1.15';
        $inOid = '1.3.6.1.2.1.31.1.1.1.6';
        $outOid = '1.3.6.1.2.1.31.1.1.1.10';
        $inErrOid = '1.3.6.1.2.1.2.2.1.14';
        $outErrOid = '1.3.6.1.2.1.2.2.1.20';
        $inDropOid = '1.3.6.1.2.1.2.2.1.13';
        $outDropOid = '1.3.6.1.2.1.2.2.1.19';
        $names = self::walkText(self::reindex(SnmpPoller::sessionWalk($session, $nameOid), $nameOid));
        $oper = self::reindex(SnmpPoller::sessionWalk($session, $operOid), $operOid);
        $speed = self::reindex(SnmpPoller::sessionWalk($session, $speedOid), $speedOid);
        $inB = self::reindex(SnmpPoller::sessionWalk($session, $inOid), $inOid);
        $outB = self::reindex(SnmpPoller::sessionWalk($session, $outOid), $outOid);
        $inErr = self::walkQuiet($session, $inErrOid);
        $outErr = self::walkQuiet($session, $outErrOid);
        $inDrop = self::walkQuiet($session, $inDropOid);
        $outDrop = self::walkQuiet($session, $outDropOid);
        $types = self::walkQuiet($session, '1.3.6.1.2.1.2.2.1.3');
        $rows = [];
        foreach ($names as $idx => $name) {
            $name = substr(trim($name), 0, 128);
            if ($name === '' || preg_match('/^Internal-/i', $name)) {
                continue;
            }
            $op = self::numeric($oper[$idx] ?? null);
            $typeN = self::numeric($types[$idx] ?? null);
            $rows[] = [
                'name' => $name,
                'type' => $typeN !== null ? (int)$typeN : null,
                'oper' => self::ifOper($op),
                'speed_mbps' => ($sp = self::numeric($speed[$idx] ?? null)) !== null ? (int)round($sp) : null,
                'in_octets' => self::octetString($inB[$idx] ?? null),
                'out_octets' => self::octetString($outB[$idx] ?? null),
                'in_errors' => self::octetString($inErr[$idx] ?? null),
                'out_errors' => self::octetString($outErr[$idx] ?? null),
                'in_discards' => self::octetString($inDrop[$idx] ?? null),
                'out_discards' => self::octetString($outDrop[$idx] ?? null),
            ];
            if (count($rows) >= self::INTERFACE_KEEP) {
                break;
            }
        }
        return $rows;
    }

    /**
     * @param array<string,mixed> $names
     * @param array<string,mixed> $used
     * @param array<string,mixed> $free
     * @return array{used:?int,free:?int,pct:?float,label:?string}
     */
    private static function memory(array $names, array $used, array $free): array
    {
        $pick = null;
        foreach ($names as $idx => $label) {
            $text = strtolower(self::text($label));
            if ($text === '') {
                continue;
            }
            if (str_contains($text, 'processor') || str_contains($text, 'system')) {
                $pick = (string)$idx;
                break;
            }
            if ($pick === null) {
                $pick = (string)$idx;
            }
        }
        if ($pick === null) {
            return ['used' => null, 'free' => null, 'pct' => null, 'label' => null];
        }
        $u = self::numeric($used[$pick] ?? null);
        $f = self::numeric($free[$pick] ?? null);
        $pct = null;
        if ($u !== null && $f !== null && ($u + $f) > 0) {
            $pct = round($u / ($u + $f) * 100, 1);
        }
        return [
            'used' => $u !== null ? (int)round($u) : null,
            'free' => $f !== null ? (int)round($f) : null,
            'pct' => $pct,
            'label' => self::text($names[$pick] ?? ''),
        ];
    }

    /**
     * CISCO-ENHANCED-MEMPOOL-MIB. 64-bit used/free when the agent has them.
     * The processor pool is the one that matches show memory. Indexes differ by platform.
     *
     * @param array<string,mixed> $names
     * @param array<string,mixed> $types
     * @param array<string,mixed> $used
     * @param array<string,mixed> $free
     * @return array{used:?int,free:?int,pct:?float,label:?string}
     */
    public static function memoryFromEnhanced(array $names, array $types, array $used, array $free): array
    {
        $best = null;
        $bestScore = -1000;
        $bestTotal = -1.0;
        foreach ($names as $idx => $label) {
            $u = self::numeric($used[$idx] ?? null);
            $f = self::numeric($free[$idx] ?? null);
            if ($u === null || $f === null || ($u + $f) <= 0) {
                continue;
            }
            $text = strtolower(self::text($label));
            $type = self::numeric($types[$idx] ?? null);
            $score = 0;
            if ($type !== null && (int)$type === 2) {
                $score += 5;
            }
            if (str_contains($text, 'processor') || str_contains($text, 'system') || str_contains($text, 'heap')) {
                $score += 3;
            }
            if (str_contains($text, 'dma') || str_contains($text, 'buffer') || str_contains($text, 'block')) {
                $score -= 2;
            }
            $total = $u + $f;
            if ($score > $bestScore || ($score === $bestScore && $total > $bestTotal)) {
                $bestScore = $score;
                $bestTotal = $total;
                $best = [
                    'used' => (int)round($u),
                    'free' => (int)round($f),
                    'pct' => round($u / $total * 100, 1),
                    'label' => self::text($label),
                ];
            }
        }
        return $best ?? ['used' => null, 'free' => null, 'pct' => null, 'label' => null];
    }

    /**
     * HOST-RESOURCES physical memory. Percent uses allocation units, so they cancel.
     *
     * @param array<string,mixed> $descr
     * @param array<string,mixed> $types
     * @param array<string,mixed> $units
     * @param array<string,mixed> $size
     * @param array<string,mixed> $used
     * @return array{used:?int,free:?int,pct:?float,label:?string}
     */
    public static function memoryFromStorage(array $descr, array $types, array $units, array $size, array $used): array
    {
        $best = null;
        $bestTotal = -1.0;
        foreach ($descr as $idx => $label) {
            $text = strtolower(self::text($label));
            $type = strtolower(self::text($types[$idx] ?? ''));
            $ram = str_contains($type, '25.2.1.2') || str_contains($type, 'hrstorageram');
            $physical = str_contains($text, 'physical') && str_contains($text, 'memory');
            if (!$ram && !$physical) {
                continue;
            }
            if (str_contains($text, 'virtual') || str_contains($text, 'swap')) {
                continue;
            }
            $sizeN = self::numeric($size[$idx] ?? null);
            $usedN = self::numeric($used[$idx] ?? null);
            $unitN = self::numeric($units[$idx] ?? null);
            if ($sizeN === null || $usedN === null || $sizeN <= 0) {
                continue;
            }
            $scale = ($unitN !== null && $unitN > 0) ? $unitN : 1.0;
            $total = $sizeN * $scale;
            if ($total <= $bestTotal) {
                continue;
            }
            $bestTotal = $total;
            $usedBytes = $usedN * $scale;
            $best = [
                'used' => (int)round($usedBytes),
                'free' => (int)round(max(0, $total - $usedBytes)),
                'pct' => round($usedN / $sizeN * 100, 1),
                'label' => self::text($label) !== '' ? self::text($label) : 'Physical memory',
            ];
        }
        return $best ?? ['used' => null, 'free' => null, 'pct' => null, 'label' => null];
    }

    /** Timeticks, or a printed "N days, H:M:S" value. */
    public static function parseUptimeSec($raw): ?int
    {
        $ticks = self::numeric($raw);
        if ($ticks !== null) {
            return (int)round($ticks / 100);
        }
        if (!is_string($raw)) {
            return null;
        }
        if (preg_match('/(\d+)\s+days?,\s*(\d+):(\d+):(\d+(?:\.\d+)?)/i', $raw, $m)) {
            $sec = ((int)$m[1]) * 86400 + ((int)$m[2]) * 3600 + ((int)$m[3]) * 60 + (float)$m[4];
            return (int)round($sec);
        }
        return null;
    }

    /** @param mixed $raw */
    public static function haStateLabel($raw): ?string
    {
        $n = self::numeric($raw);
        if ($n !== null) {
            $map = [2 => 'up', 8 => 'backup', 9 => 'active', 10 => 'standby'];
            if (isset($map[(int)$n])) {
                return $map[(int)$n];
            }
        }
        $text = strtolower(self::text($raw));
        foreach (['active', 'standby', 'backup', 'primary', 'secondary'] as $word) {
            if ($text !== '' && str_contains($text, $word)) {
                return $word;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $values */
    public static function firstHaRole(array $values): ?string
    {
        $fallback = null;
        foreach ($values as $raw) {
            $label = self::haStateLabel($raw);
            if ($label === null) {
                continue;
            }
            if (in_array($label, ['active', 'standby', 'backup', 'primary', 'secondary'], true)) {
                return $label;
            }
            $fallback = $fallback ?? $label;
        }
        return $fallback;
    }

    /**
     * Null when this Net-SNMP build can authenticate with the chosen protocols.
     * SHA-2 and AES-192/256 arrived in Net-SNMP 5.8.
     */
    public static function libraryBlocksProtocol(string $netSnmpVersion, string $auth, string $priv): ?string
    {
        $auth = strtoupper(str_replace(['-', ' '], '', trim($auth)));
        $priv = strtoupper(str_replace(['-', ' '], '', trim($priv)));
        if ($auth === 'SHA1') {
            $auth = 'SHA';
        }
        if ($priv === 'AES128') {
            $priv = 'AES';
        }
        $modern = in_array($auth, ['SHA224', 'SHA256', 'SHA384', 'SHA512'], true)
            || in_array($priv, ['AES192', 'AES256'], true);
        if (!$modern) {
            return null;
        }
        $netSnmpVersion = trim($netSnmpVersion);
        if ($netSnmpVersion === '' || version_compare($netSnmpVersion, '5.8.0', '>=')) {
            return null;
        }
        return 'SNMPv3 ' . $auth . '/' . $priv . ' does not authenticate with Net-SNMP ' . $netSnmpVersion
            . ' on this server. Use SHA and AES, or a PHP build linked to Net-SNMP 5.8 or newer.';
    }

    public static function netSnmpVersion(): string
    {
        static $version = null;
        if ($version !== null) {
            return $version;
        }
        $version = '';
        if (!function_exists('phpinfo')) {
            return $version;
        }
        ob_start();
        @phpinfo(INFO_MODULES);
        $info = ob_get_clean();
        if (is_string($info) && preg_match('/NET-SNMP Version\s*(?:=>|<\/td>\s*<td[^>]*>)\s*([0-9]+\.[0-9]+\.[0-9]+)/i', $info, $m)) {
            $version = $m[1];
        }
        return $version;
    }

    public static function netSnmpSupportsSha2(): bool
    {
        $version = self::netSnmpVersion();
        return $version === '' || version_compare($version, '5.8.0', '>=');
    }

    public static function snmpLibraryBlock(string $auth, string $priv): ?string
    {
        return self::libraryBlocksProtocol(self::netSnmpVersion(), $auth, $priv);
    }

    /** @param array<string,mixed> $session */
    private static function enhancedMemory(array $session): array
    {
        $base = '1.3.6.1.4.1.9.9.221.1.1.1.1.';
        $names = self::walkQuiet($session, $base . '3');
        if (!$names) {
            return ['used' => null, 'free' => null, 'pct' => null, 'label' => null];
        }
        $types = self::walkQuiet($session, $base . '2');
        $used = self::walkQuiet($session, $base . '18');
        $free = self::walkQuiet($session, $base . '20');
        if (self::firstNumber($used) === null && self::firstNumber($free) === null) {
            $used = self::walkQuiet($session, $base . '7');
            $free = self::walkQuiet($session, $base . '8');
        }
        return self::memoryFromEnhanced($names, $types, $used, $free);
    }

    /** @param array<string,mixed> $session */
    private static function hostMemory(array $session): array
    {
        $base = '1.3.6.1.2.1.25.2.3.1.';
        return self::memoryFromStorage(
            self::walkQuiet($session, $base . '3'),
            self::walkQuiet($session, $base . '2'),
            self::walkQuiet($session, $base . '4'),
            self::walkQuiet($session, $base . '5'),
            self::walkQuiet($session, $base . '6')
        );
    }

    private static function storageSkip(string $label, string $type): bool
    {
        if (str_contains($type, '25.2.1.2') || str_contains($type, '25.2.1.3')) {
            return true;
        }
        foreach (['memory', 'ram', 'swap', 'virtual', 'tmpfs', 'cgroup', 'shm'] as $word) {
            if (str_contains($label, $word)) {
                return true;
            }
        }
        return false;
    }

    private static function storageKeep(string $label, string $type): bool
    {
        if (str_contains($type, '25.2.1.4') || str_contains($type, 'hrstoragefixeddisk')) {
            return true;
        }
        foreach (['disk', 'flash', 'storage', 'ssd', 'nvme', 'mmc', 'volume', 'boot', '/ngfw', '/opt', '/var', 'hard disk', 'harddisk'] as $word) {
            if (str_contains($label, $word)) {
                return true;
            }
        }
        return false;
    }

    /** @param mixed $raw */
    private static function indexNumber($raw): string
    {
        if (is_int($raw) || is_float($raw)) {
            return (string)(int)$raw;
        }
        $text = self::text($raw);
        if ($text !== '' && preg_match('/(\d+)\s*$/', $text, $m)) {
            return $m[1];
        }
        return '';
    }

    /** @param array<string,mixed> $walk */
    private static function firstNumber(array $walk): ?float
    {
        foreach ($walk as $raw) {
            $n = self::numeric($raw);
            if ($n !== null) {
                return round($n, 1);
            }
        }
        return null;
    }

    /**
     * Align a walk to the row index even when the agent returns a full OID.
     *
     * @param array<string,mixed> $walk
     * @return array<string,mixed>
     */
    private static function reindex(array $walk, string $columnOid): array
    {
        $columnOid = ltrim($columnOid, '.');
        $colNodes = explode('.', $columnOid);
        $out = [];
        foreach ($walk as $k => $v) {
            $key = self::instanceKey((string)$k, $columnOid, $colNodes);
            if ($key === '') {
                continue;
            }
            $out[$key] = $v;
        }
        return $out;
    }

    /** @param list<string> $colNodes */
    private static function instanceKey(string $key, string $columnOid, array $colNodes): string
    {
        $key = trim($key);
        if (preg_match_all('/\d+(?:\.\d+)*/', $key, $m) && $m[0]) {
            $num = (string)$m[0][count($m[0]) - 1];
        } else {
            $num = trim($key, '.');
        }
        if ($num === '') {
            return '';
        }
        if (str_contains($num, $columnOid)) {
            $pos = strpos($num, $columnOid);
            return ltrim(substr($num, (int)$pos + strlen($columnOid)), '.');
        }
        $numNodes = explode('.', $num);
        $colCount = count($colNodes);
        for ($i = 0; $i < $colCount; $i++) {
            $slice = array_slice($colNodes, $i);
            $len = count($slice);
            if ($len < 4) {
                break;
            }
            if (count($numNodes) <= $len) {
                continue;
            }
            if (array_slice($numNodes, 0, $len) === $slice) {
                return implode('.', array_slice($numNodes, $len));
            }
        }
        return $num;
    }

    /** @param array<string,mixed> $session */
    private static function modelFromEntities(array $session): ?string
    {
        $oid = '1.3.6.1.2.1.47.1.1.1.1.13';
        $models = self::walkText(self::reindex(SnmpPoller::sessionWalk($session, $oid), $oid));
        foreach ($models as $label) {
            $guess = self::parseSysDescr($label);
            if (!empty($guess['model'])) {
                return $guess['model'];
            }
        }
        return null;
    }

    /** @param array<string,mixed> $session */
    private static function chassisSerial(array $session): ?string
    {
        $oid = '1.3.6.1.2.1.47.1.1.1.1.11';
        $serials = self::walkText(self::reindex(SnmpPoller::sessionWalk($session, $oid), $oid));
        foreach ($serials as $raw) {
            $s = trim($raw);
            if (preg_match('/^[A-Z0-9-]{6,40}$/i', $s) && !preg_match('/^[0-9A-F]{2}(?::[0-9A-F]{2}){5}$/i', $s)) {
                return $s;
            }
        }
        return null;
    }

    /** @param mixed $raw */
    private static function numeric($raw): ?float
    {
        $n = SnmpPoller::sessionToNumber($raw);
        if ($n !== null) {
            return $n;
        }
        if (is_string($raw) && preg_match('/\((\d+)\)/', $raw, $m)) {
            return (float)$m[1];
        }
        return null;
    }

    /** @param array<string,mixed> $walk @return array<string,string> */
    private static function walkText(array $walk): array
    {
        $out = [];
        foreach ($walk as $k => $v) {
            $out[(string)$k] = self::text($v);
        }
        return $out;
    }

    /** @param mixed $raw */
    private static function text($raw): string
    {
        if ($raw === null || $raw === false) {
            return '';
        }
        $s = trim((string)$raw);
        $s = preg_replace('/^(?:STRING|OCTET STRING|Hex-STRING|OID|INTEGER|Integer32|Gauge32|Counter32|Counter64|Unsigned32|Timeticks|TimeTicks)\s*:\s*/i', '', $s) ?? $s;
        return trim($s, "\" \t");
    }

    /** @param array<string,mixed> $session */
    private static function tryGet(array $session, string $oid)
    {
        try {
            return SnmpPoller::sessionGet($session, $oid);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * A missing counter must not fail the poll.
     *
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    private static function walkQuiet(array $session, string $oid): array
    {
        try {
            return self::reindex(SnmpPoller::sessionWalk($session, $oid), $oid);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @param mixed $raw */
    private static function gauge($raw): ?int
    {
        $n = self::numeric($raw);
        if ($n === null || $n < 0 || !is_finite($n)) {
            return null;
        }
        return (int)round($n);
    }

    /** @param array<string,mixed> $snapshot */
    private static function sampleInt(array $snapshot, string $key): ?int
    {
        if (!array_key_exists($key, $snapshot) || $snapshot[$key] === null || $snapshot[$key] === '') {
            return null;
        }
        if (!is_numeric($snapshot[$key])) {
            return null;
        }
        $n = (float)$snapshot[$key];
        if ($n < 0 || !is_finite($n)) {
            return null;
        }
        return (int)round($n);
    }

    /**
     * Largest active-translation gauge on the box.
     * The maximum avoids adding the same count once per entity.
     *
     * @param array<string,mixed> $session
     */
    private static function translationActive(array $session): ?int
    {
        $best = null;
        foreach (self::walkQuiet($session, self::OID_XLATE_ACTIVE) as $raw) {
            $n = self::gauge($raw);
            if ($n === null) {
                continue;
            }
            if ($best === null || $n > $best) {
                $best = $n;
            }
        }
        return $best;
    }

    private static function hardwareStatus(?float $code, string $detail): string
    {
        $map = [
            1 => 'other',
            2 => 'up',
            3 => 'down',
            4 => 'error',
            5 => 'over temp',
            6 => 'busy',
            7 => 'no media',
            8 => 'backup',
            9 => 'active',
            10 => 'standby',
        ];
        if ($code !== null && isset($map[(int)$code])) {
            return $map[(int)$code];
        }
        return $detail;
    }

    /** @param array<string,mixed> $snapshot */
    private static function samplePct(array $snapshot, string $key): ?float
    {
        if (!isset($snapshot[$key]) || !is_numeric($snapshot[$key])) {
            return null;
        }
        return round((float)$snapshot[$key], 2);
    }

    /** @param array<string,mixed> $snapshot */
    private static function snapshotHasCounters(array $snapshot): bool
    {
        if (trim((string)($snapshot['sys_descr'] ?? '')) !== '' || trim((string)($snapshot['sys_name'] ?? '')) !== '') {
            return true;
        }
        if (isset($snapshot['uptime_sec']) && is_numeric($snapshot['uptime_sec'])) {
            return true;
        }
        if (!empty($snapshot['interfaces'])) {
            return true;
        }
        $mem = is_array($snapshot['memory'] ?? null) ? $snapshot['memory'] : [];
        if (isset($mem['pct']) && is_numeric($mem['pct'])) {
            return true;
        }
        foreach (['connections', 'cpu_pct', 'cpu_lina_pct', 'cpu_snort_pct', 'disk_pct'] as $key) {
            if (isset($snapshot[$key]) && is_numeric($snapshot[$key])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $session
     * @return list<array{name:string,value:float}>
     */
    private static function cpuRows(array $session): array
    {
        $physOid = '1.3.6.1.4.1.9.9.109.1.1.1.1.2';
        $nameOid = '1.3.6.1.2.1.47.1.1.1.1.7';
        $descrOid = '1.3.6.1.2.1.47.1.1.1.1.2';
        $loads = [];
        foreach (self::CPU_LOAD_OIDS as $loadOid) {
            $loads = self::reindex(SnmpPoller::sessionWalk($session, $loadOid), $loadOid);
            if (self::firstNumber($loads) !== null) {
                break;
            }
        }
        $phys = self::reindex(SnmpPoller::sessionWalk($session, $physOid), $physOid);
        $names = self::walkText(self::reindex(SnmpPoller::sessionWalk($session, $nameOid), $nameOid));
        $descrs = self::walkText(self::reindex(SnmpPoller::sessionWalk($session, $descrOid), $descrOid));
        $rows = [];
        foreach ($loads as $idx => $raw) {
            $value = self::numeric($raw);
            if ($value === null) {
                continue;
            }
            $physIdx = self::indexNumber($phys[$idx] ?? null);
            $name = trim((string)($names[$physIdx] ?? ''));
            if ($name === '') {
                $name = trim((string)($descrs[$physIdx] ?? ''));
            }
            $rows[] = [
                'name' => $name,
                'value' => $value,
            ];
        }
        return $rows;
    }

    /**
     * @param array<string,mixed> $session
     * @return list<array{label:string,size:float,used:float}>
     */
    private static function diskRows(array $session): array
    {
        $descrOid = '1.3.6.1.2.1.25.2.3.1.3';
        $typeOid = '1.3.6.1.2.1.25.2.3.1.2';
        $sizeOid = '1.3.6.1.2.1.25.2.3.1.5';
        $usedOid = '1.3.6.1.2.1.25.2.3.1.6';
        $descr = self::walkText(self::reindex(SnmpPoller::sessionWalk($session, $descrOid), $descrOid));
        $types = self::walkText(self::reindex(SnmpPoller::sessionWalk($session, $typeOid), $typeOid));
        $size = self::reindex(SnmpPoller::sessionWalk($session, $sizeOid), $sizeOid);
        $used = self::reindex(SnmpPoller::sessionWalk($session, $usedOid), $usedOid);
        $rows = [];
        foreach ($descr as $idx => $label) {
            $sizeN = self::numeric($size[$idx] ?? null);
            $usedN = self::numeric($used[$idx] ?? null);
            if ($sizeN === null || $usedN === null) {
                continue;
            }
            $rows[] = [
                'label' => $label,
                'type' => (string)($types[$idx] ?? ''),
                'size' => $sizeN,
                'used' => $usedN,
            ];
        }
        return $rows;
    }

    /**
     * Temperatures, fans, and power rails from ENTITY-SENSOR-MIB. Healthy rows stay out of the alert strip.
     *
     * @param array<string,mixed> $session
     * @return list<array{label:string,detail:string,status:string}>
     */
    private static function sensorRows(array $session): array
    {
        $nameOid = '1.3.6.1.2.1.47.1.1.1.1.7';
        $typeOid = '1.3.6.1.4.1.9.9.91.1.1.1.1.1';
        $valueOid = '1.3.6.1.4.1.9.9.91.1.1.1.1.4';
        $scaleOid = '1.3.6.1.4.1.9.9.91.1.1.1.1.2';
        $names = self::walkText(self::reindex(SnmpPoller::sessionWalk($session, $nameOid), $nameOid));
        $types = self::reindex(SnmpPoller::sessionWalk($session, $typeOid), $typeOid);
        $values = self::reindex(SnmpPoller::sessionWalk($session, $valueOid), $valueOid);
        $scales = self::reindex(SnmpPoller::sessionWalk($session, $scaleOid), $scaleOid);
        $units = [4 => 'V', 5 => 'V', 6 => 'A', 8 => 'C', 10 => 'rpm'];
        $rows = [];
        foreach ($types as $idx => $rawType) {
            $type = self::numeric($rawType);
            if ($type === null || !isset($units[(int)$type])) {
                continue;
            }
            $value = self::numeric($values[$idx] ?? null);
            if ($value === null) {
                continue;
            }
            $scale = self::numeric($scales[$idx] ?? null);
            $scaled = $scale === null ? $value : $value * (10 ** ((int)$scale - 9));
            $label = trim((string)($names[$idx] ?? 'Sensor'));
            if ($label === '') {
                $label = 'Sensor';
            }
            $unit = $units[(int)$type];
            $shown = abs($scaled - round($scaled)) < 0.05 ? (string)(int)round($scaled) : (string)round($scaled, 1);
            $rows[] = ['label' => $label, 'detail' => $shown . ' ' . $unit, 'status' => 'ok'];
            if (count($rows) >= 24) {
                break;
            }
        }
        return $rows;
    }

    private static function ifOper(?float $code): string
    {
        $map = [1 => 'up', 2 => 'down', 3 => 'testing', 4 => 'unknown', 5 => 'dormant', 6 => 'not present', 7 => 'lower layer down'];
        if ($code !== null && isset($map[(int)$code])) {
            return $map[(int)$code];
        }
        return 'unknown';
    }

    /**
     * IF-MIB ifType label. Parses the code locally so the firewall page can
     * render a row without loading the SNMP poller.
     */
    public static function ifTypeLabel($raw): string
    {
        if (is_int($raw) || (is_float($raw) && is_finite($raw))) {
            $code = (int)$raw;
        } elseif (is_string($raw) && preg_match('/(\d+)/', $raw, $m)) {
            $code = (int)$m[1];
        } else {
            return '';
        }
        $map = [
            1 => 'other',
            6 => 'ethernet',
            24 => 'loopback',
            53 => 'virtual',
            117 => 'gigabit ethernet',
            131 => 'tunnel',
            135 => 'vlan',
            136 => 'vlan',
            161 => 'lag',
        ];
        return $map[$code] ?? (string)$code;
    }
}
