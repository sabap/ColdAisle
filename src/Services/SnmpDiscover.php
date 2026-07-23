<?php
/**
 * Live SNMP discovery — walk common roots, score power-related OIDs.
 */
declare(strict_types=1);

require_once __DIR__ . '/SnmpPoller.php';

class SnmpDiscover
{
    /** Enterprise / system roots to walk (bounded). */
    private const WALK_ROOTS = [
        '1.3.6.1.2.1.1',           // MIB-II system
        '1.3.6.1.4.1.318',         // APC / Schneider
        '1.3.6.1.4.1.3808',        // CyberPower
        '1.3.6.1.4.1.13742',       // Raritan
        '1.3.6.1.4.1.21239',       // Vertiv / Geist
        '1.3.6.1.4.1.1718',        // Server Technology
        '1.3.6.1.4.1.99999',       // ColdAisle lab agent
    ];

    private const MAX_OIDS = 400;
    private const WALK_TIMEOUT_SEC = 8;

    /**
     * @param array{
     *   host:string,port?:int,snmp_version?:string,
     *   security_name?:string,auth_protocol?:string,auth_passphrase?:string,
     *   priv_protocol?:string,priv_passphrase?:string,context?:string,
     *   community?:string
     * } $creds
     * @return array{
     *   ok:bool,host:string,sysDescr:?string,candidates:list<array>,
     *   proposed_map:array<string,string>,walk_count:int,message:string
     * }
     */
    public static function discover(array $creds): array
    {
        $host = trim((string)($creds['host'] ?? ''));
        if ($host === '') {
            throw new RuntimeException('Host / IP is required for discovery.');
        }
        if (!function_exists('snmp3_real_walk') && !function_exists('snmprealwalk') && !function_exists('snmp3_get')) {
            throw new RuntimeException('PHP SNMP extension is not available.');
        }

        @snmp_set_quick_print(true);
        if (defined('SNMP_VALUE_PLAIN')) {
            @snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
        }
        @ini_set('max_execution_time', '60');

        $port = (int)($creds['port'] ?? 161);
        $hostPort = $host . ($port !== 161 ? ':' . $port : '');
        $version = strtolower((string)($creds['snmp_version'] ?? '3'));

        $sysDescr = self::snmpGet($hostPort, $version, $creds, '1.3.6.1.2.1.1.1.0');
        $collected = [];
        $errors = [];

        foreach (self::WALK_ROOTS as $root) {
            if (count($collected) >= self::MAX_OIDS) {
                break;
            }
            try {
                $walk = self::snmpWalk($hostPort, $version, $creds, $root);
                foreach ($walk as $oid => $val) {
                    $oid = self::normalizeOid((string)$oid);
                    if ($oid === '' || isset($collected[$oid])) {
                        continue;
                    }
                    $collected[$oid] = $val;
                    if (count($collected) >= self::MAX_OIDS) {
                        break 2;
                    }
                }
            } catch (Throwable $e) {
                $errors[] = $root . ': ' . $e->getMessage();
            }
        }

        // Always try lab + common leaf GETs even if walk failed
        $leafGets = [
            '1.3.6.1.2.1.1.3.0',
            '1.3.6.1.4.1.99999.2.1.0',
            '1.3.6.1.4.1.99999.2.2.0',
            '1.3.6.1.4.1.99999.2.3.0',
            '1.3.6.1.4.1.318.1.1.1.4.2.3.0',
            '1.3.6.1.4.1.318.1.1.1.4.2.8.0',
            '1.3.6.1.4.1.318.1.1.12.2.3.1.1.2.1',
            '1.3.6.1.4.1.3808.1.1.1.4.2.3.0',
            '1.3.6.1.4.1.3808.1.1.1.4.2.5.0',
        ];
        foreach ($leafGets as $oid) {
            if (isset($collected[$oid])) {
                continue;
            }
            $v = self::snmpGet($hostPort, $version, $creds, $oid);
            if ($v !== null && $v !== false) {
                $collected[$oid] = $v;
            }
        }

        if (!$collected && $sysDescr === null) {
            $detail = $errors ? implode('; ', array_slice($errors, 0, 3)) : 'No response';
            throw new RuntimeException('SNMP discovery failed: ' . $detail);
        }

        $candidates = [];
        foreach ($collected as $oid => $raw) {
            $score = self::scoreOid($oid, $raw);
            if ($score < 1) {
                continue;
            }
            $num = self::toNumber($raw);
            $candidates[] = [
                'oid' => $oid,
                'value' => is_scalar($raw) ? (string)$raw : json_encode($raw),
                'numeric' => $num,
                'score' => $score,
                'hint' => self::hintFor($oid, $raw, $num),
            ];
        }
        usort($candidates, static function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        $candidates = array_slice($candidates, 0, 80);

        $proposed = self::proposeMap($candidates, $sysDescr);
        if (!$proposed) {
            $proposed = [
                'sysDescr' => '1.3.6.1.2.1.1.1.0',
                'sysUpTime' => '1.3.6.1.2.1.1.3.0',
            ];
        }

        return [
            'ok' => true,
            'host' => $host,
            'sysDescr' => is_string($sysDescr) ? $sysDescr : null,
            'candidates' => $candidates,
            'proposed_map' => $proposed,
            'walk_count' => count($collected),
            'message' => count($candidates)
                ? ('Found ' . count($candidates) . ' candidate OID(s) from ' . count($collected) . ' objects.')
                : ('Walked ' . count($collected) . ' object(s); limited power candidates — review and edit map.'),
        ];
    }

    /**
     * Build a stable template name: Vendor+Model
     */
    public static function templateName(string $vendor, string $model): string
    {
        $v = self::sanitizePart($vendor);
        $m = self::sanitizePart($model);
        if ($v === '' || $m === '') {
            throw new RuntimeException('Vendor and model are required for template naming.');
        }
        return $v . '+' . $m;
    }

    public static function sanitizePart(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        $s = preg_replace('/[^\w.\- +#]/', '', $s) ?? $s;
        $s = trim($s);
        return mb_substr($s, 0, 60);
    }

    private static function snmpGet(string $hostPort, string $version, array $creds, string $oid)
    {
        try {
            if ($version === '3' && function_exists('snmp3_get')) {
                $sec = self::secLevel($creds);
                return @snmp3_get(
                    $hostPort,
                    (string)($creds['security_name'] ?? ''),
                    $sec,
                    (string)($creds['auth_protocol'] ?: 'SHA'),
                    (string)($creds['auth_passphrase'] ?? ''),
                    (string)($creds['priv_protocol'] ?: 'AES'),
                    (string)($creds['priv_passphrase'] ?? ''),
                    $oid
                );
            }
            if (function_exists('snmp2_get')) {
                $community = (string)($creds['community'] ?? $creds['security_name'] ?? 'public');
                return @snmp2_get($hostPort, $community, $oid);
            }
        } catch (Throwable $e) {
            return null;
        }
        return null;
    }

    /** @return array<string,mixed> */
    private static function snmpWalk(string $hostPort, string $version, array $creds, string $root): array
    {
        $result = false;
        if ($version === '3' && function_exists('snmp3_real_walk')) {
            $sec = self::secLevel($creds);
            $result = @snmp3_real_walk(
                $hostPort,
                (string)($creds['security_name'] ?? ''),
                $sec,
                (string)($creds['auth_protocol'] ?: 'SHA'),
                (string)($creds['auth_passphrase'] ?? ''),
                (string)($creds['priv_protocol'] ?: 'AES'),
                (string)($creds['priv_passphrase'] ?? ''),
                $root
            );
        } elseif (function_exists('snmprealwalk')) {
            $community = (string)($creds['community'] ?? $creds['security_name'] ?? 'public');
            $result = @snmprealwalk($hostPort, $community, $root);
        }
        if ($result === false || !is_array($result)) {
            throw new RuntimeException('Walk failed for ' . $root);
        }
        return $result;
    }

    private static function secLevel(array $creds): string
    {
        $auth = trim((string)($creds['auth_passphrase'] ?? ''));
        $priv = trim((string)($creds['priv_passphrase'] ?? ''));
        if ($auth !== '' && $priv !== '') {
            return 'authPriv';
        }
        if ($auth !== '') {
            return 'authNoPriv';
        }
        return 'noAuthNoPriv';
    }

    private static function normalizeOid(string $oid): string
    {
        $oid = trim($oid);
        // snmp may return "SNMPv2-MIB::sysDescr.0" or ".1.3.6..."
        if (preg_match('/(\d+(?:\.\d+)+)$/', $oid, $m)) {
            return $m[1];
        }
        $oid = ltrim($oid, '.');
        return preg_match('/^\d/', $oid) ? $oid : '';
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

    private static function scoreOid(string $oid, $raw): int
    {
        $score = 0;
        $num = self::toNumber($raw);
        $s = strtolower($oid . ' ' . (is_string($raw) ? $raw : ''));

        // Prefer enterprise power trees
        if (str_starts_with($oid, '1.3.6.1.4.1.')) {
            $score += 2;
        }
        if (str_starts_with($oid, '1.3.6.1.4.1.99999.2.')) {
            $score += 8; // lab power metrics
        }
        if (str_contains($s, 'watt') || str_contains($s, 'power') || str_contains($s, 'activepower')) {
            $score += 6;
        }
        if (str_contains($s, 'amp') || str_contains($s, 'current') || str_contains($s, 'loadstatusload')) {
            $score += 5;
        }
        if (str_contains($s, 'load') && $num !== null) {
            $score += 3;
        }
        if (str_contains($s, 'volt')) {
            $score += 2;
        }
        if (str_contains($s, 'temp')) {
            $score += 2;
        }
        if ($num !== null && $num >= 0) {
            $score += 1;
            // Prefer plausible power ranges
            if ($num > 0 && $num < 500000) {
                $score += 1;
            }
        }
        // Skip pure sysObjectID style huge integers only if zero score otherwise
        return $score;
    }

    private static function hintFor(string $oid, $raw, ?float $num): string
    {
        $hints = [];
        if (str_starts_with($oid, '1.3.6.1.4.1.99999.2.1')) {
            $hints[] = 'lab watts';
        }
        if (str_starts_with($oid, '1.3.6.1.4.1.99999.2.2')) {
            $hints[] = 'lab amps×10';
        }
        if (str_contains(strtolower($oid), '318') && $num !== null && $num <= 100) {
            $hints[] = 'possible load %';
        }
        if ($num !== null && $num > 100 && $num < 20000) {
            $hints[] = 'possible watts';
        }
        if ($num !== null && $num > 0 && $num < 100) {
            $hints[] = 'possible amps or %';
        }
        return $hints ? implode(', ', $hints) : 'candidate';
    }

    /**
     * @param list<array{oid:string,numeric:?float,score:int,hint:string}> $candidates
     * @return array<string,string>
     */
    private static function proposeMap(array $candidates, $sysDescr): array
    {
        $map = [
            'sysDescr' => '1.3.6.1.2.1.1.1.0',
            'sysUpTime' => '1.3.6.1.2.1.1.3.0',
        ];
        $watts = null;
        $amps = null;
        $ampsX10 = null;

        foreach ($candidates as $c) {
            $oid = $c['oid'];
            $n = $c['numeric'];
            if ($watts === null && (
                str_contains($c['hint'], 'watts')
                || str_starts_with($oid, '1.3.6.1.4.1.99999.2.1')
                || ($n !== null && $n >= 50 && $n <= 100000 && str_contains($c['hint'], 'possible watts'))
            )) {
                $watts = $oid;
            }
            if ($ampsX10 === null && str_starts_with($oid, '1.3.6.1.4.1.99999.2.2')) {
                $ampsX10 = $oid;
            }
            if ($amps === null && $ampsX10 === null && (
                str_contains($c['hint'], 'amps')
                || ($n !== null && $n > 0 && $n < 80 && str_contains($c['hint'], 'possible amps'))
            )) {
                $amps = $oid;
            }
        }

        // Fallbacks: highest-scoring enterprise numeric OIDs
        if ($watts === null) {
            foreach ($candidates as $c) {
                if ($c['numeric'] !== null && $c['numeric'] >= 20 && $c['score'] >= 5
                    && str_starts_with($c['oid'], '1.3.6.1.4.1.')) {
                    $watts = $c['oid'];
                    break;
                }
            }
        }
        if ($ampsX10 !== null) {
            $map['amps_x10'] = $ampsX10;
        } elseif ($amps !== null) {
            $map['amps'] = $amps;
        }
        if ($watts !== null) {
            $map['watts'] = $watts;
        }
        return $map;
    }

    /**
     * Credentials array from a devices row.
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    public static function credsFromDevice(array $device): array
    {
        $host = trim((string)($device['mgmt_ip'] ?? ''));
        if ($host === '') {
            $host = trim((string)($device['primary_ip'] ?? ''));
        }
        $version = strtolower((string)($device['snmp_version'] ?? '3'));
        if ($version === '') {
            $version = '3';
        }
        $creds = [
            'host' => $host,
            'port' => 161,
            'snmp_version' => $version,
            'security_name' => (string)($device['snmp_v3_user'] ?? ''),
            'auth_protocol' => (string)($device['snmp_v3_auth_proto'] ?? 'SHA'),
            'auth_passphrase' => (string)($device['snmp_v3_auth_pass'] ?? ''),
            'priv_protocol' => (string)($device['snmp_v3_priv_proto'] ?? 'AES'),
            'priv_passphrase' => (string)($device['snmp_v3_priv_pass'] ?? ''),
            'community' => (string)($device['snmp_community'] ?? 'public'),
        ];
        // Profile overrides when set
        if (!empty($device['snmp_v3_profile_id'])) {
            try {
                $prof = Database::fetchOne(
                    'SELECT * FROM snmp_v3_profiles WHERE profile_id = ? AND is_active = 1',
                    [(int)$device['snmp_v3_profile_id']]
                );
                if ($prof) {
                    $creds['security_name'] = (string)($prof['security_name'] ?? $creds['security_name']);
                    $creds['auth_protocol'] = (string)($prof['auth_protocol'] ?? $creds['auth_protocol']);
                    $creds['priv_protocol'] = (string)($prof['priv_protocol'] ?? $creds['priv_protocol']);
                    if (!empty($prof['auth_passphrase'])) {
                        $creds['auth_passphrase'] = (string)$prof['auth_passphrase'];
                    }
                    if (!empty($prof['priv_passphrase'])) {
                        $creds['priv_passphrase'] = (string)$prof['priv_passphrase'];
                    }
                    $creds['snmp_version'] = '3';
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
        return $creds;
    }

    /**
     * Pre-flight: vendor (manufacturer), model, and IP must be set.
     * @param array<string,mixed> $device
     * @return array{ok:bool,vendor:string,model:string,host:string,missing:list<string>}
     */
    public static function discoverPrereqs(array $device): array
    {
        $vendor = trim((string)($device['manufacturer'] ?? ''));
        $model = trim((string)($device['model'] ?? ''));
        $host = trim((string)($device['mgmt_ip'] ?? ''));
        if ($host === '') {
            $host = trim((string)($device['primary_ip'] ?? ''));
        }
        $missing = [];
        if ($vendor === '') {
            $missing[] = 'manufacturer (vendor)';
        }
        if ($model === '') {
            $missing[] = 'model';
        }
        if ($host === '') {
            $missing[] = 'management or primary IP';
        }
        return [
            'ok' => $missing === [],
            'vendor' => $vendor,
            'model' => $model,
            'host' => $host,
            'missing' => $missing,
        ];
    }

    /** @return array<string,mixed>|null */
    public static function findSiteTemplateByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        try {
            $row = Database::fetchOne(
                'SELECT * FROM snmp_site_oid_templates WHERE name = ?',
                [$name]
            );
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function getSiteTemplate(int $templateId): ?array
    {
        if ($templateId < 1) {
            return null;
        }
        try {
            $row = Database::fetchOne(
                'SELECT * FROM snmp_site_oid_templates WHERE template_id = ?',
                [$templateId]
            );
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Create or overwrite a site OID template named Vendor+Model.
     * If the name already exists and $overwrite is false, returns exists=true without writing.
     *
     * @param array<string,string> $oidMap
     * @return array{
     *   exists?:bool, overwritten?:bool, created?:bool,
     *   template_id:int, name:string, oid_map:array<string,string>
     * }
     */
    public static function saveSiteTemplate(
        string $vendor,
        string $model,
        array $oidMap,
        bool $overwrite = false,
        string $source = 'discovered',
        ?string $notes = null
    ): array {
        $name = self::templateName($vendor, $model);
        $cleanMap = [];
        foreach ($oidMap as $k => $v) {
            $k = trim((string)$k);
            $v = trim((string)$v);
            if ($k === '' || $v === '' || str_starts_with($k, '_')) {
                continue;
            }
            if (!preg_match('/^\d/', $v)) {
                continue;
            }
            $cleanMap[$k] = $v;
        }
        if (!$cleanMap) {
            throw new RuntimeException('OID map is empty — cannot create template.');
        }

        $existing = self::findSiteTemplateByName($name);
        if ($existing && !$overwrite) {
            return [
                'exists' => true,
                'template_id' => (int)$existing['template_id'],
                'name' => $name,
                'oid_map' => json_decode((string)($existing['oid_map'] ?? '{}'), true) ?: [],
            ];
        }

        $payload = [
            'name' => $name,
            'vendor' => self::sanitizePart($vendor),
            'model' => self::sanitizePart($model),
            'oid_map' => json_encode($cleanMap, JSON_UNESCAPED_SLASHES),
            'source' => $source !== '' ? $source : 'discovered',
            'notes' => $notes,
            'is_active' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            Database::update(
                'snmp_site_oid_templates',
                $payload,
                'template_id = :id',
                [':id' => (int)$existing['template_id']]
            );
            return [
                'overwritten' => true,
                'template_id' => (int)$existing['template_id'],
                'name' => $name,
                'oid_map' => $cleanMap,
            ];
        }

        $id = Database::insert('snmp_site_oid_templates', $payload);
        return [
            'created' => true,
            'template_id' => (int)$id,
            'name' => $name,
            'oid_map' => $cleanMap,
        ];
    }

    /**
     * Assign a site template to a device (OIDs live on the template, not the device).
     */
    public static function assignTemplateToDevice(int $deviceId, int $templateId): void
    {
        Database::update('devices', [
            'snmp_site_template_id' => $templateId,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'device_id = :id', [':id' => $deviceId]);
    }

    /**
     * Pre-flight for PDUs: vendor (manufacturer), model, and IP must be set.
     * @param array<string,mixed> $pdu
     * @return array{ok:bool,vendor:string,model:string,host:string,missing:list<string>}
     */
    public static function discoverPrereqsPdu(array $pdu): array
    {
        $vendor = trim((string)($pdu['manufacturer'] ?? ''));
        $model = trim((string)($pdu['model'] ?? ''));
        $host = trim((string)($pdu['ip_address'] ?? ''));
        $missing = [];
        if ($vendor === '') {
            $missing[] = 'manufacturer (vendor)';
        }
        if ($model === '') {
            $missing[] = 'model';
        }
        if ($host === '') {
            $missing[] = 'IP address';
        }
        return [
            'ok' => $missing === [],
            'vendor' => $vendor,
            'model' => $model,
            'host' => $host,
            'missing' => $missing,
        ];
    }

    /**
     * Credentials array from a pdus row.
     * @param array<string,mixed> $pdu
     * @return array<string,mixed>
     */
    public static function credsFromPdu(array $pdu): array
    {
        $host = trim((string)($pdu['ip_address'] ?? ''));
        $version = strtolower((string)($pdu['snmp_version'] ?? '3'));
        if ($version === '') {
            $version = '3';
        }
        $community = (string)($pdu['snmp_community'] ?? 'public');
        $secName = (string)($pdu['snmp_security_name'] ?? '');
        // v1/v2c: security_name often holds community in targets; prefer explicit community
        if (($version === '1' || $version === '2c') && $secName === '') {
            $secName = $community !== '' ? $community : 'public';
        }
        $creds = [
            'host' => $host,
            'port' => (int)($pdu['snmp_port'] ?? 161) ?: 161,
            'snmp_version' => $version,
            'security_name' => $secName,
            'auth_protocol' => (string)($pdu['snmp_auth_protocol'] ?? 'SHA'),
            'auth_passphrase' => (string)($pdu['snmp_auth_passphrase'] ?? ''),
            'priv_protocol' => (string)($pdu['snmp_priv_protocol'] ?? 'AES'),
            'priv_passphrase' => (string)($pdu['snmp_priv_passphrase'] ?? ''),
            'community' => $community !== '' ? $community : 'public',
            'context' => (string)($pdu['snmp_context'] ?? ''),
        ];
        if (!empty($pdu['snmp_v3_profile_id'])) {
            try {
                $prof = Database::fetchOne(
                    'SELECT * FROM snmp_v3_profiles WHERE profile_id = ? AND is_active = 1',
                    [(int)$pdu['snmp_v3_profile_id']]
                );
                if ($prof) {
                    $creds['security_name'] = (string)($prof['security_name'] ?? $creds['security_name']);
                    $creds['auth_protocol'] = (string)($prof['auth_protocol'] ?? $creds['auth_protocol']);
                    $creds['priv_protocol'] = (string)($prof['priv_protocol'] ?? $creds['priv_protocol']);
                    if (!empty($prof['auth_passphrase'])) {
                        $creds['auth_passphrase'] = (string)$prof['auth_passphrase'];
                    }
                    if (!empty($prof['priv_passphrase'])) {
                        $creds['priv_passphrase'] = (string)$prof['priv_passphrase'];
                    }
                    if (!empty($prof['context_name'])) {
                        $creds['context'] = (string)$prof['context_name'];
                    }
                    $creds['snmp_version'] = '3';
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
        return $creds;
    }

    /**
     * Assign site template to PDU only (no snmp_targets row).
     * Poll now / scheduler use the template via snmp_site_template_id + optional snmp_auto_poll.
     */
    public static function assignTemplateToPdu(int $pduId, int $templateId): void
    {
        Database::update('pdus', [
            'snmp_site_template_id' => $templateId,
            'snmp_enabled' => 1,
        ], 'pdu_id = :id', [':id' => $pduId]);
    }
}
