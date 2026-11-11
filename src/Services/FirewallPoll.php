<?php
/**
 * Cisco ASA stats over SNMP (CISCO-FIREWALL-MIB, IF-MIB, HOST resources).
 * Does not collect license activation keys.
 */
declare(strict_types=1);

class FirewallPoll
{
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
        $cps = self::numeric(self::tryGet($session, '1.3.6.1.4.1.9.9.147.1.2.2.3.0'));
        $cpsPeak = self::numeric(self::tryGet($session, '1.3.6.1.4.1.9.9.147.1.2.2.4.0'));
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
            'connections_per_sec' => $cps !== null ? (int)round($cps) : null,
            'connections_per_sec_peak' => $cpsPeak !== null ? (int)round($cpsPeak) : null,
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
        $names = self::walkText(self::reindex(SnmpPoller::sessionWalk($session, $nameOid), $nameOid));
        $oper = self::reindex(SnmpPoller::sessionWalk($session, $operOid), $operOid);
        $speed = self::reindex(SnmpPoller::sessionWalk($session, $speedOid), $speedOid);
        $inB = self::reindex(SnmpPoller::sessionWalk($session, $inOid), $inOid);
        $outB = self::reindex(SnmpPoller::sessionWalk($session, $outOid), $outOid);
        $rows = [];
        foreach ($names as $idx => $name) {
            $name = trim($name);
            if ($name === '' || preg_match('/^Internal-/i', $name)) {
                continue;
            }
            $op = self::numeric($oper[$idx] ?? null);
            $rows[] = [
                'name' => $name,
                'oper' => self::ifOper($op),
                'speed_mbps' => ($sp = self::numeric($speed[$idx] ?? null)) !== null ? (int)round($sp) : null,
                'in_octets' => ($n = self::numeric($inB[$idx] ?? null)) !== null ? (int)round($n) : null,
                'out_octets' => ($n = self::numeric($outB[$idx] ?? null)) !== null ? (int)round($n) : null,
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

    /** @param mixed $raw */
    private static function tryGet(array $session, string $oid)
    {
        try {
            return SnmpPoller::sessionGet($session, $oid);
        } catch (Throwable $e) {
            return null;
        }
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
