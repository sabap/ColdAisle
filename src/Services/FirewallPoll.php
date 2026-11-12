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
                $snapshot = self::collect($session);
            } finally {
                SnmpPoller::closeSessionPublic($session);
            }
            self::save($fw, $snapshot);
            return $snapshot;
        } catch (Throwable $e) {
            self::recordError($fw, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    public static function collect(array $session): array
    {
        $descr = self::text(self::tryGet($session, '1.3.6.1.2.1.1.1.0'));
        $name = self::text(self::tryGet($session, '1.3.6.1.2.1.1.5.0'));
        $ticks = self::numeric(self::tryGet($session, '1.3.6.1.2.1.1.3.0'));
        $ident = self::parseSysDescr($descr);
        $cpu = self::firstNumber(SnmpPoller::sessionWalk($session, '1.3.6.1.4.1.9.9.109.1.1.1.1.8'));
        $memNames = '1.3.6.1.4.1.9.9.48.1.1.1.2';
        $memUsed = '1.3.6.1.4.1.9.9.48.1.1.1.5';
        $memFree = '1.3.6.1.4.1.9.9.48.1.1.1.6';
        $mem = self::memory(
            self::reindex(SnmpPoller::sessionWalk($session, $memNames), $memNames),
            self::reindex(SnmpPoller::sessionWalk($session, $memUsed), $memUsed),
            self::reindex(SnmpPoller::sessionWalk($session, $memFree), $memFree)
        );
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
            'uptime_sec' => $ticks !== null ? (int)round($ticks / 100) : null,
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
        }
        return ['version' => $version, 'model' => $model];
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
            if (str_contains($blob, 'this device')) {
                $thisUnit = $status !== '' ? $status : $det;
            } elseif ($peer === null && (str_contains($blob, 'secondary') || str_contains($blob, 'primary'))) {
                if (!str_contains($blob, 'this device')) {
                    $peer = $status !== '' ? $status : $det;
                }
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
        return $sawInUse ? $best : null;
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
        $rows = [];
        foreach ($names as $idx => $name) {
            $name = substr(trim($name), 0, 128);
            if ($name === '' || preg_match('/^Internal-/i', $name)) {
                continue;
            }
            $op = self::numeric($oper[$idx] ?? null);
            $rows[] = [
                'name' => $name,
                'oper' => self::ifOper($op),
                'speed_mbps' => ($sp = self::numeric($speed[$idx] ?? null)) !== null ? (int)round($sp) : null,
                'in_octets' => self::octetString($inB[$idx] ?? null),
                'out_octets' => self::octetString($outB[$idx] ?? null),
                'in_errors' => self::octetString($inErr[$idx] ?? null),
                'out_errors' => self::octetString($outErr[$idx] ?? null),
                'in_discards' => self::octetString($inDrop[$idx] ?? null),
                'out_discards' => self::octetString($outDrop[$idx] ?? null),
            ];
            if (count($rows) >= 32) {
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

    private static function ifOper(?float $code): string
    {
        $map = [1 => 'up', 2 => 'down', 3 => 'testing', 4 => 'unknown', 5 => 'dormant', 6 => 'not present', 7 => 'lower layer down'];
        if ($code !== null && isset($map[(int)$code])) {
            return $map[(int)$code];
        }
        return 'unknown';
    }
}
