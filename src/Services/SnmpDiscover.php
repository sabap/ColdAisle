<?php
/**
 * Live SNMP discovery — walk common roots, score power-related OIDs.
 * Phase 1.5: keep MIB symbolic names, name-based scoring/hints, UI name column.
 */
declare(strict_types=1);

require_once __DIR__ . '/SnmpPoller.php';

class SnmpDiscover
{
    /**
     * Enterprise / system roots to walk (bounded).
     * Narrow trees first so env managers / probes fill the candidate budget before
     * a broad PowerNet walk exhausts MAX_OIDS (or hangs IIS).
     */
    private const WALK_ROOTS = [
        '1.3.6.1.2.1.1',              // MIB-II system
        '1.3.6.1.4.1.318.1.1.10',     // APC Environmental Monitoring (AP9340 / IEM / probes)
        '1.3.6.1.4.1.318.1.1.25',     // APC Universal I/O / modular sensors
        '1.3.6.1.4.1.318.1.1.26',     // APC rPDU2 (metered PDUs)
        '1.3.6.1.4.1.318.1.1.12',     // APC rPDU
        '1.3.6.1.4.1.318.1.1.3',      // APC UPS-ish (when present)
        '1.3.6.1.4.1.3808',           // CyberPower
        '1.3.6.1.4.1.13742',          // Raritan
        '1.3.6.1.4.1.21239',          // Vertiv / Geist
        '1.3.6.1.4.1.1718',           // Server Technology
        '1.3.6.1.4.1.99999',          // ColdAisle lab agent
        // Broad APC last — can be huge; only fills remaining MAX_OIDS budget
        '1.3.6.1.4.1.318',
    ];

    private const MAX_OIDS = 400;
    /** Per-walk timeout passed to snmp*_real_walk (microseconds). */
    private const WALK_TIMEOUT_USEC = 3_000_000;
    private const WALK_RETRIES = 0;
    /** Candidates shown in Discover UI (high-signal only). */
    private const MAX_DISPLAY_CANDIDATES = 40;
    /** Minimum score to appear in the Discover table. */
    private const MIN_DISPLAY_SCORE = 14;
    /** Wider pool used only for proposeMap (still noise-filtered). */
    private const MAX_PROPOSE_POOL = 120;
    private const MIN_PROPOSE_SCORE = 6;

    /**
     * @param array{
     *   host:string,port?:int,snmp_version?:string,
     *   security_name?:string,auth_protocol?:string,auth_passphrase?:string,
     *   priv_protocol?:string,priv_passphrase?:string,context?:string,
     *   community?:string
     * } $creds
     * @return array{
     *   ok:bool,host:string,sysDescr:?string,candidates:list<array>,
     *   proposed_map:array<string,string>,walk_count:int,message:string,
     *   named_count:int
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

        // Avoid IIS FastCGI 500 from PHP timeouts on slow agents
        @ini_set('max_execution_time', '90');
        if (function_exists('set_time_limit')) {
            @set_time_limit(90);
        }

        $version = strtolower(trim((string)($creds['snmp_version'] ?? '3')));
        if ($version === 'v3') {
            $version = '3';
        }
        if ($version === '3') {
            $secName = trim((string)($creds['security_name'] ?? ''));
            if ($secName === '') {
                throw new RuntimeException(
                    'SNMPv3 security name (user) is empty. Save SNMP version 3 and a credential profile '
                    . '(or enter the v3 user) on the device, then try Discover again.'
                );
            }
        }

        // Load uploaded vendor MIBs so walks can resolve symbolic names when available
        $mibsLoaded = 0;
        if (class_exists('MibService')) {
            try {
                $mibsLoaded = MibService::loadAll();
            } catch (Throwable $e) {
                App::log('SnmpDiscover MIB load: ' . $e->getMessage(), 'warning');
                $mibsLoaded = 0;
            }
        }

        $port = (int)($creds['port'] ?? 161);
        if ($port <= 0 || $port > 65535) {
            $port = 161;
        }
        $hostPort = $host . ($port !== 161 ? ':' . $port : '');

        // Values as plain numbers when possible
        @snmp_set_quick_print(true);
        if (defined('SNMP_VALUE_PLAIN')) {
            @snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
        }

        $sysDescr = self::snmpGet($hostPort, $version, $creds, '1.3.6.1.2.1.1.1.0');
        /** @var array<string,array{raw:mixed,name:?string,module:?string,raw_key:string}> $collected */
        $collected = [];
        $errors = [];

        // 1) Prefer MODULE::name keys when MIBs resolve
        self::setOidOutputFormat('module');
        self::collectWalks($hostPort, $version, $creds, $collected, $errors);

        // 2) Fill gaps with pure numeric OIDs (portable maps; names kept from module pass)
        self::setOidOutputFormat('numeric');
        self::collectWalks($hostPort, $version, $creds, $collected, $errors);

        // Always try lab + common leaf GETs even if walk failed
        $leafGets = [
            '1.3.6.1.2.1.1.3.0',
            '1.3.6.1.4.1.99999.2.1.0',
            '1.3.6.1.4.1.99999.2.2.0',
            '1.3.6.1.4.1.99999.2.3.0',
            '1.3.6.1.4.1.318.1.1.1.4.2.3.0',
            '1.3.6.1.4.1.318.1.1.1.4.2.8.0',
            '1.3.6.1.4.1.318.1.1.12.1.6.0', // rPDUIdentSerialNumber
            '1.3.6.1.4.1.318.1.1.26.2.1.9.0', // rPDU2IdentSerialNumber (scalar form)
            '1.3.6.1.4.1.318.1.1.26.2.1.9.1',
            '1.3.6.1.4.1.318.1.1.12.2.3.1.1.2.1',
            '1.3.6.1.4.1.318.1.1.26.6.3.1.7.1', // rPDU2 phase power-ish (common)
            // rPDU2 outlet metered status (table column.instance) — walks often hit MAX_OIDS first
            '1.3.6.1.4.1.318.1.1.26.9.4.3.1.6.1', // StatusCurrent.1
            '1.3.6.1.4.1.318.1.1.26.9.4.3.1.7.1', // StatusPower.1
            '1.3.6.1.4.1.318.1.1.26.9.4.3.1.5.1', // StatusState.1
            '1.3.6.1.4.1.318.1.1.26.9.4.3.1.3.1', // StatusName.1
            '1.3.6.1.4.1.318.1.1.26.9.4.3.1.6.1.1', // module-index style Current.1.1
            '1.3.6.1.4.1.3808.1.1.1.4.2.3.0',
            '1.3.6.1.4.1.3808.1.1.1.4.2.5.0',
            // APC Integrated Environmental Monitor / AP9340-class probe tables (common instances)
            '1.3.6.1.4.1.318.1.1.10.2.3.2.1.4.1',  // iemStatusProbeCurrentTemp (example index)
            '1.3.6.1.4.1.318.1.1.10.2.3.2.1.6.1',  // iemStatusProbeCurrentHumid
            '1.3.6.1.4.1.318.1.1.10.4.2.3.1.5.1',  // emStatusProbe* style (varies by firmware)
            '1.3.6.1.4.1.318.1.1.10.4.2.3.1.6.1',
            '1.3.6.1.4.1.318.1.1.10.3.13.1.1.3.1', // uioSensorStatusTemperature-ish
            '1.3.6.1.4.1.318.1.1.10.3.13.1.1.4.1', // uioSensorStatusHumidity-ish
        ];
        foreach ($leafGets as $oid) {
            if (isset($collected[$oid])) {
                continue;
            }
            $v = self::snmpGet($hostPort, $version, $creds, $oid);
            if ($v !== null && $v !== false) {
                $collected[$oid] = [
                    'raw' => $v,
                    'name' => null,
                    'module' => null,
                    'raw_key' => $oid,
                ];
            }
        }

        if (!$collected && $sysDescr === null) {
            $detail = $errors ? implode('; ', array_slice($errors, 0, 3)) : 'No response';
            throw new RuntimeException('SNMP discovery failed: ' . $detail);
        }

        // Offline MIB index: map numeric OIDs → Module::name when Net-SNMP still
        // returns numeric keys (typical on Windows even after snmp_read_mib).
        $indexSize = 0;
        if (class_exists('MibService')) {
            $indexSize = MibService::oidIndexSize();
        }

        $scored = [];
        $namedCount = 0;
        $indexHits = 0;
        $noiseSkipped = 0;
        foreach ($collected as $oid => $meta) {
            $raw = $meta['raw'];
            $name = $meta['name'] ?? null;
            $module = $meta['module'] ?? null;

            // Prefer portable numeric form in the map when we have one
            $mapOid = $oid;
            if (!preg_match('/^\d+(?:\.\d+)*$/', $oid) && $name) {
                // Symbolic-only key from module walk — keep for polling with MIBs loaded
                $mapOid = $oid;
            }

            // Prefer portable normalized numeric OID (leading iso(1))
            if (preg_match('/^\d+(?:\.\d+)*$/', $mapOid)) {
                $mapOid = class_exists('MibService')
                    ? MibService::normalizeNumericOid($mapOid)
                    : self::normalizeNumericOidLocal($mapOid);
            }

            if (!$name && class_exists('MibService') && preg_match('/^\d+(?:\.\d+)*$/', $mapOid)) {
                $resolved = MibService::resolveOidName($mapOid);
                if ($resolved) {
                    $name = $resolved['name'];
                    $module = $resolved['module'];
                    $indexHits++;
                }
            }

            // Hard-drop config / threshold / reset / identity noise before scoring
            if (self::isDiscoverNoise($mapOid, $name, $raw)) {
                $noiseSkipped++;
                continue;
            }

            $num = self::toNumber($raw);
            $score = self::scoreOid($mapOid, $name, $raw, $num);
            if ($score < 1) {
                continue;
            }
            $scored[] = [
                'oid' => $mapOid,
                'name' => $name !== null ? self::utf8Safe($name) : null,
                'module' => $module !== null ? self::utf8Safe($module) : null,
                'value' => self::utf8Safe(is_scalar($raw) ? (string)$raw : (json_encode($raw) ?: '')),
                'numeric' => $num,
                'score' => $score,
                'hint' => self::utf8Safe(self::hintFor($mapOid, $name, $raw, $num)),
            ];
        }
        usort($scored, static function ($a, $b) {
            $cmp = $b['score'] <=> $a['score'];
            if ($cmp !== 0) {
                return $cmp;
            }
            // Prefer named OIDs when scores tie
            $an = $a['name'] ? 1 : 0;
            $bn = $b['name'] ? 1 : 0;
            return $bn <=> $an;
        });

        // Proposed map uses a wider noise-filtered pool (not only the UI table)
        $proposePool = [];
        foreach ($scored as $c) {
            if (($c['score'] ?? 0) < self::MIN_PROPOSE_SCORE) {
                continue;
            }
            $proposePool[] = $c;
            if (count($proposePool) >= self::MAX_PROPOSE_POOL) {
                break;
            }
        }
        $proposed = self::proposeMap($proposePool, $sysDescr);
        if (!$proposed) {
            $proposed = [
                'sysDescr' => '1.3.6.1.2.1.1.1.0',
                'sysUpTime' => '1.3.6.1.2.1.1.3.0',
            ];
        }
        // Always try known APC rPDU2 outlet table bases when the agent responds
        // (full enterprise walks often exhaust MAX_OIDS before 26.9 outlet tables).
        $proposed = self::injectApcOutletBases($proposed, $hostPort, $version, $creds, $sysDescr, $collected);

        // Serial number from walk / leaf GETs (also propose map key)
        $serialHit = self::extractSerialFromCollected($collected);
        if ($serialHit) {
            $proposed['serial_no'] = $serialHit['oid'];
            // Surface as a top identity candidate even if filtered as "noise"
            array_unshift($scored, [
                'oid' => $serialHit['oid'],
                'name' => $serialHit['name'],
                'module' => $serialHit['module'],
                'value' => $serialHit['value'],
                'numeric' => null,
                'score' => 100,
                'hint' => 'PDU serial number → serial_no field',
            ]);
        }

        // UI table: high-signal only
        $candidates = [];
        foreach ($scored as $c) {
            if (($c['score'] ?? 0) < self::MIN_DISPLAY_SCORE) {
                continue;
            }
            if (!empty($c['name'])) {
                $namedCount++;
            }
            $candidates[] = $c;
            if (count($candidates) >= self::MAX_DISPLAY_CANDIDATES) {
                break;
            }
        }
        // If everything scored mid-tier, show top slice so Discover is not empty
        if (!$candidates && $scored) {
            $candidates = array_slice($scored, 0, min(20, count($scored)));
            $namedCount = 0;
            foreach ($candidates as $c) {
                if (!empty($c['name'])) {
                    $namedCount++;
                }
            }
        }

        $outletKeyCount = 0;
        foreach (array_keys($proposed) as $pk) {
            if (preg_match('/^outlet_(amps|watts|power|current|name|state)\b/i', (string)$pk)) {
                $outletKeyCount++;
            }
        }

        $msg = 'Walked ' . count($collected) . ' object(s)';
        if (count($candidates)) {
            $msg = 'Found ' . count($candidates) . ' high-signal candidate OID(s) from '
                . count($collected) . ' objects';
            if ($noiseSkipped > 0) {
                $msg .= ' (filtered ' . $noiseSkipped . ' config/identity OIDs)';
            }
            if ($serialHit) {
                $msg .= '; serial ' . $serialHit['value'];
            }
            if ($outletKeyCount > 0) {
                $msg .= '; ' . $outletKeyCount . ' outlet table column(s) in proposed map';
            }
            if ($namedCount) {
                $msg .= '; ' . $namedCount . ' with MIB names';
            }
            if ($indexHits) {
                $msg .= ' (' . $indexHits . ' from uploaded MIB text index)';
            }
            if ($mibsLoaded) {
                $msg .= '; ' . $mibsLoaded . ' MIB file(s) loaded into Net-SNMP';
            }
            if ($indexSize) {
                $msg .= '; offline index ' . $indexSize . ' objects';
            }
            $msg .= '.';
        } else {
            $msg .= '; limited power candidates - review and edit map.';
            if ($outletKeyCount > 0) {
                $msg .= ' Outlet table columns still proposed (' . $outletKeyCount . ').';
            }
        }

        if ($mibsLoaded > 0 && $namedCount === 0 && $indexSize === 0) {
            $msg .= ' Could not parse OBJECT-TYPE assignments from uploaded MIBs.';
        } elseif ($indexSize > 0 && $namedCount === 0) {
            $msg .= ' Offline MIB index built but no walk OIDs matched (different enterprise tree or incomplete IMPORT chain in the MIB file).';
        }

        // Sanitize map values for JSON (OID strings are ASCII; metric keys may include vendor text)
        $safeProposed = [];
        foreach ($proposed as $k => $v) {
            $safeProposed[self::utf8Safe((string)$k)] = self::utf8Safe((string)$v);
        }

        return [
            'ok' => true,
            'host' => self::utf8Safe($host),
            'sysDescr' => is_string($sysDescr) ? self::utf8Safe($sysDescr) : null,
            'candidates' => $candidates,
            'proposed_map' => $safeProposed,
            'serial_no' => isset($serialHit['value']) ? self::utf8Safe((string)$serialHit['value']) : null,
            'serial_oid' => $serialHit['oid'] ?? null,
            'walk_count' => count($collected),
            'named_count' => $namedCount,
            'mibs_loaded' => $mibsLoaded,
            'mib_index_size' => $indexSize,
            'mib_index_hits' => $indexHits,
            'message' => self::utf8Safe($msg),
        ];
    }

    /** Make SNMP / MIB strings safe for JSON responses (Windows Net-SNMP often returns latin-1). */
    public static function utf8Safe(string $s): string
    {
        if ($s === '') {
            return '';
        }
        // Strip NULs and most C0 controls (keep tab/LF/CR)
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? $s;
        if (function_exists('mb_check_encoding') && mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($s, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
            if (is_string($converted)) {
                return $converted;
            }
        }
        return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $s) ?? $s;
    }

    /**
     * Find manufacturer serial from walked OIDs (APC rPDUIdentSerialNumber, etc.).
     *
     * @param array<string,array{raw:mixed,name:?string,module:?string}> $collected
     * @return array{oid:string,value:string,name:?string,module:?string}|null
     */
    private static function extractSerialFromCollected(array $collected): ?array
    {
        $best = null;
        $bestScore = -1;
        foreach ($collected as $oid => $meta) {
            $name = strtolower((string)($meta['name'] ?? ''));
            $mapOid = (string)$oid;
            if (preg_match('/^\d+(?:\.\d+)*$/', $mapOid)) {
                $mapOid = class_exists('MibService')
                    ? MibService::normalizeNumericOid($mapOid)
                    : self::normalizeNumericOidLocal($mapOid);
            }
            // Resolve name if missing
            $dispName = $meta['name'] ?? null;
            $module = $meta['module'] ?? null;
            if (!$dispName && class_exists('MibService') && preg_match('/^\d+(?:\.\d+)*$/', $mapOid)) {
                $resolved = MibService::resolveOidName($mapOid);
                if ($resolved) {
                    $dispName = $resolved['name'];
                    $module = $resolved['module'];
                    $name = strtolower($dispName);
                }
            }

            $hay = $name . ' ' . strtolower((string)$dispName) . ' ' . $mapOid;
            // Prefer PDU body serial over NMC card serial
            $score = 0;
            if (preg_match('/identserialnumber|ident.*serial(?!.*nmc)/', $hay)
                || preg_match('/rpduidentserial|rpdu2identserialnumber/', $hay)
            ) {
                $score = 50;
            } elseif (preg_match('/serialnumber|serial_no|serialnum/', $hay)
                && !preg_match('/nmc|battery|pack|outlet|module|card/', $hay)
            ) {
                $score = 30;
            } elseif (preg_match('/nmcserial/', $hay)) {
                $score = 10; // last resort
            } else {
                continue;
            }

            $val = self::cleanSerialValue($meta['raw'] ?? null);
            if ($val === null || $val === '') {
                continue;
            }
            // Known APC OIDs boost
            if (str_starts_with($mapOid, '1.3.6.1.4.1.318.1.1.12.1.6')
                || str_starts_with($mapOid, '1.3.6.1.4.1.318.1.1.26.2.1.9')
            ) {
                $score += 20;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'oid' => $mapOid,
                    'value' => $val,
                    'name' => $dispName,
                    'module' => $module,
                ];
            }
        }
        return $best;
    }

    /** @param mixed $raw */
    public static function cleanSerialValue($raw): ?string
    {
        if ($raw === null || $raw === false) {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            $s = (string)$raw;
        } elseif (is_string($raw)) {
            $s = trim($raw);
            // Strip SNMP type prefix: STRING: "ABC123"
            if (preg_match('/^(?:STRING|OCTET\s*STRING|Hex-STRING)\s*:\s*(.*)$/i', $s, $m)) {
                $s = trim($m[1]);
            }
            $s = trim($s, " \t\"'");
        } else {
            return null;
        }
        if ($s === '' || strcasecmp($s, 'null') === 0 || $s === '""') {
            return null;
        }
        // Reject obvious non-serials
        if (strlen($s) > 80 || preg_match('/[\r\n]/', $s)) {
            return null;
        }
        return $s;
    }

    /**
     * Write serial onto PDU when empty (does not overwrite a user-entered serial).
     * @return bool true if the row was updated
     */
    public static function applySerialToPduIfEmpty(int $pduId, ?string $serial): bool
    {
        $serial = self::cleanSerialValue($serial);
        if ($serial === null || $serial === '' || $pduId < 1) {
            return false;
        }
        try {
            $row = Database::fetchOne('SELECT serial_no FROM pdus WHERE pdu_id = ?', [$pduId]);
            if (!$row) {
                return false;
            }
            $cur = trim((string)($row['serial_no'] ?? ''));
            if ($cur !== '') {
                return false;
            }
            Database::update('pdus', ['serial_no' => $serial], 'pdu_id = :id', [':id' => $pduId]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @param 'module'|'numeric' $mode */
    private static function setOidOutputFormat(string $mode): void
    {
        if (!function_exists('snmp_set_oid_output_format')) {
            return;
        }
        if ($mode === 'module') {
            if (defined('SNMP_OID_OUTPUT_MODULE')) {
                @snmp_set_oid_output_format(SNMP_OID_OUTPUT_MODULE);
            } elseif (defined('SNMP_OID_OUTPUT_FULL')) {
                @snmp_set_oid_output_format(SNMP_OID_OUTPUT_FULL);
            }
            return;
        }
        if (defined('SNMP_OID_OUTPUT_NUMERIC')) {
            @snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
        }
    }

    /**
     * @param array<string,array{raw:mixed,name:?string,module:?string,raw_key:string}> $collected
     * @param list<string> $errors
     */
    private static function collectWalks(
        string $hostPort,
        string $version,
        array $creds,
        array &$collected,
        array &$errors
    ): void {
        foreach (self::WALK_ROOTS as $root) {
            if (count($collected) >= self::MAX_OIDS) {
                break;
            }
            try {
                $walk = self::snmpWalk($hostPort, $version, $creds, $root);
                foreach ($walk as $key => $val) {
                    $parsed = self::parseOidKey((string)$key);
                    $oid = $parsed['oid'];
                    if ($oid === '') {
                        continue;
                    }
                    if (!isset($collected[$oid])) {
                        $collected[$oid] = [
                            'raw' => $val,
                            'name' => $parsed['name'],
                            'module' => $parsed['module'],
                            'raw_key' => $parsed['raw_key'],
                        ];
                    } elseif (empty($collected[$oid]['name']) && $parsed['name']) {
                        $collected[$oid]['name'] = $parsed['name'];
                        $collected[$oid]['module'] = $parsed['module'];
                        $collected[$oid]['raw_key'] = $parsed['raw_key'];
                    }
                    if (count($collected) >= self::MAX_OIDS) {
                        return;
                    }
                }
            } catch (Throwable $e) {
                $errors[] = $root . ': ' . $e->getMessage();
            }
        }
    }

    /**
     * Parse a walk array key into numeric OID + optional symbolic name.
     * When Net-SNMP returns only Module::name.instance (no dotted OID), the
     * symbolic form is used as the oid string (pollable when MIBs are loaded).
     *
     * @return array{oid:string,name:?string,module:?string,raw_key:string}
     */
    private static function parseOidKey(string $key): array
    {
        $rawKey = trim($key);
        $name = null;
        $module = null;
        $oid = '';

        // PowerNet-MIB::rPDU2PhaseStatusLoad.1  or  SNMPv2-MIB::sysDescr.0
        if (preg_match(
            '/([A-Za-z][A-Za-z0-9_-]*)::([A-Za-z][A-Za-z0-9_-]*(?:\.\d+)*)/',
            $rawKey,
            $m
        )) {
            $module = $m[1];
            $name = $m[1] . '::' . $m[2];
        }

        // Full or trailing dotted numeric OID (at least two arcs: 1.3 …)
        if (preg_match('/(\d+(?:\.\d+)+)/', $rawKey, $m)) {
            $oid = ltrim($m[1], '.');
            $oid = class_exists('MibService')
                ? MibService::normalizeNumericOid($oid)
                : self::normalizeNumericOidLocal($oid);
        }

        // Symbolic-only key (common with SNMP_OID_OUTPUT_MODULE)
        if ($oid === '' && $name !== null) {
            $oid = $name;
        }

        return [
            'oid' => $oid,
            'name' => $name,
            'module' => $module,
            'raw_key' => $rawKey,
        ];
    }

    /** Fallback when MibService is unavailable. */
    private static function normalizeNumericOidLocal(string $oid): string
    {
        $oid = ltrim(trim($oid), '.');
        if (preg_match('/^3\.6\.1(?:\.|$)/', $oid)) {
            return '1.' . $oid;
        }
        return $oid;
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
                $authProto = self::normalizeSnmpProtocol((string)($creds['auth_protocol'] ?? 'SHA'), 'auth');
                $privProto = self::normalizeSnmpProtocol((string)($creds['priv_protocol'] ?? 'AES'), 'priv');
                return @snmp3_get(
                    $hostPort,
                    (string)($creds['security_name'] ?? ''),
                    $sec,
                    $authProto,
                    (string)($creds['auth_passphrase'] ?? ''),
                    $privProto,
                    (string)($creds['priv_passphrase'] ?? ''),
                    $oid,
                    self::WALK_TIMEOUT_USEC,
                    self::WALK_RETRIES
                );
            }
            if (function_exists('snmp2_get')) {
                $community = (string)($creds['community'] ?? $creds['security_name'] ?? 'public');
                return @snmp2_get($hostPort, $community, $oid, self::WALK_TIMEOUT_USEC, self::WALK_RETRIES);
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
            $authProto = self::normalizeSnmpProtocol((string)($creds['auth_protocol'] ?? 'SHA'), 'auth');
            $privProto = self::normalizeSnmpProtocol((string)($creds['priv_protocol'] ?? 'AES'), 'priv');
            $result = @snmp3_real_walk(
                $hostPort,
                (string)($creds['security_name'] ?? ''),
                $sec,
                $authProto,
                (string)($creds['auth_passphrase'] ?? ''),
                $privProto,
                (string)($creds['priv_passphrase'] ?? ''),
                $root,
                self::WALK_TIMEOUT_USEC,
                self::WALK_RETRIES
            );
        } elseif (function_exists('snmprealwalk')) {
            $community = (string)($creds['community'] ?? $creds['security_name'] ?? 'public');
            $result = @snmprealwalk($hostPort, $community, $root, self::WALK_TIMEOUT_USEC, self::WALK_RETRIES);
        }
        if ($result === false || !is_array($result)) {
            throw new RuntimeException('Walk failed for ' . $root);
        }
        return $result;
    }

    private static function secLevel(array $creds): string
    {
        // Prefer explicit level from device/profile when valid
        $explicit = strtolower(trim((string)($creds['security_level'] ?? $creds['snmp_v3_sec_level'] ?? '')));
        $explicit = str_replace([' ', '_'], '', $explicit);
        if (in_array($explicit, ['noauthnopriv', 'authnopriv', 'authpriv'], true)) {
            return match ($explicit) {
                'authpriv' => 'authPriv',
                'authnopriv' => 'authNoPriv',
                default => 'noAuthNoPriv',
            };
        }
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

    /** Normalize auth/priv protocol names for PHP snmp3_* (expects MD5/SHA/AES…). */
    private static function normalizeSnmpProtocol(string $proto, string $kind): string
    {
        $p = strtoupper(trim($proto));
        $p = str_replace(['-', ' '], '', $p);
        if ($kind === 'auth') {
            $map = [
                'MD5' => 'MD5',
                'SHA' => 'SHA',
                'SHA1' => 'SHA',
                'SHA224' => 'SHA224',
                'SHA256' => 'SHA256',
                'SHA384' => 'SHA384',
                'SHA512' => 'SHA512',
            ];
            return $map[$p] ?? ($p !== '' ? $p : 'SHA');
        }
        $map = [
            'DES' => 'DES',
            'AES' => 'AES',
            'AES128' => 'AES',
            'AES192' => 'AES192',
            'AES256' => 'AES256',
        ];
        return $map[$p] ?? ($p !== '' ? $p : 'AES');
    }

    /**
     * Coerce SNMP values to numbers. Does NOT scrape digits out of model strings
     * like "AP8861" (that previously became 8861 and scored as "possible watts").
     */
    private static function toNumber($raw): ?float
    {
        if ($raw === null || $raw === false) {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            return (float)$raw;
        }
        if (is_bool($raw)) {
            return null;
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
        // Pure number
        if (is_numeric($s)) {
            return (float)$s;
        }
        // SNMP type prefixes: "INTEGER: 42", "Gauge32: 100"
        if (preg_match(
            '/^(?:INTEGER|Integer32|Gauge32|Counter(?:32|64)|Unsigned32|Opaque|Timeticks|TimeTicks)\s*:\s*([-+]?\d+(?:\.\d+)?)\s*$/i',
            $s,
            $m
        )) {
            return (float)$m[1];
        }
        // Number with optional unit only: "1234", "12.5 W", "3.2 A", "45 %"
        if (preg_match('/^([-+]?\d+(?:\.\d+)?)\s*(?:W|kW|V|A|VA|%|watts?|amps?|volts?)?\s*$/i', $s, $m)) {
            return (float)$m[1];
        }
        // Timeticks: (12345) Timeticks: ...
        if (preg_match('/^\(\s*(\d+)\s*\)/', $s, $m)) {
            return (float)$m[1];
        }
        return null;
    }

    /** True when raw value is clearly a non-metric string (model, serial, hostname…). */
    private static function isNonMetricString($raw): bool
    {
        if (!is_string($raw)) {
            return false;
        }
        $s = trim($raw);
        if ($s === '' || is_numeric($s)) {
            return false;
        }
        // Type-prefixed numbers are metrics
        if (preg_match('/^(?:INTEGER|Integer32|Gauge32|Counter(?:32|64)|Unsigned32|Timeticks|TimeTicks)\s*:/i', $s)) {
            return false;
        }
        // Has letters (AP8861, "Rack PDU", hostnames) → not a power number
        if (preg_match('/[A-Za-z]/', $s)) {
            // Allow pure unit suffixes already handled by toNumber
            if (preg_match('/^[-+]?\d+(?:\.\d+)?\s*(?:W|kW|V|A|VA|%|watts?|amps?|volts?)\s*$/i', $s)) {
                return false;
            }
            return true;
        }
        return false;
    }

    /** Lowercase haystack of name + oid for keyword scans. */
    private static function nameHaystack(string $oid, ?string $name): string
    {
        return strtolower(($name ?? '') . ' ' . $oid);
    }

    /**
     * Hard-reject OIDs that clutter Discover (config, thresholds, resets, identity).
     * Live status metrics (power/current/voltage/VA/PF/peak/PS) return false.
     */
    private static function isDiscoverNoise(string $oid, ?string $name, $raw): bool
    {
        $s = self::nameHaystack($oid, $name);

        // Always drop pure identity / non-metric strings (model AP8861, timestamps as text)
        if (self::isNonMetricString($raw)) {
            // Allow only if name is clearly a live metric (rare string enums)
            if (!preg_match('/loadstate|powersupply.*status|status$/i', $s)) {
                return true;
            }
        }

        // Config / write / audit — never useful as map candidates in the UI list
        if (preg_match(
            '/\bconfig\b|threshold|nearoverload|overloadpower|lowload|'
            . 'reset|timestamp|starttime|start_time|'
            . 'orientation|displayorientation|hardwarerev|firmwarerev|'
            . 'identmodel|identserial|identname|partnumber|'
            . 'trap|notification|control\b|initiate|'
            . 'phaseconfig|deviceconfig|outletconfig|'
            . 'index\b|numphases|numoutlets|module\b|'
            . 'peakpowerstart|peakcurrentstart|peakpowerreset|peakcurrentreset/',
            $s
        )) {
            // Keep useful rating / max phase current (not a threshold alarm point)
            if (preg_match('/maxphasecurrentrating|identdevicerating|devicerating|maxcurrentrating/', $s)
                && !preg_match('/threshold|near|overload|lowload|reset/', $s)
            ) {
                return false;
            }
            return true;
        }

        // Table index / entry shells without a metric leaf
        if ($name && preg_match('/::(.*)(table|entry)$/i', $name)) {
            return true;
        }

        return false;
    }

    private static function scoreOid(string $oid, ?string $name, $raw, ?float $num): int
    {
        $score = 0;
        $s = self::nameHaystack($oid, $name);
        $nonMetric = self::isNonMetricString($raw);

        // Prefer enterprise power trees
        if (str_starts_with($oid, '1.3.6.1.4.1.')) {
            $score += 2;
        }
        if (str_starts_with($oid, '1.3.6.1.4.1.99999.2.')) {
            $score += 8; // lab power metrics
        }
        // APC / Schneider
        if (str_starts_with($oid, '1.3.6.1.4.1.318.')) {
            $score += 2;
        }

        // Model / serial / hostname strings must not rank as power metrics
        if ($nonMetric) {
            $score -= 6;
        }

        // Soft demote remaining secondary enums (PS / load state still allowed)
        if (preg_match('/properties|identhardware|identdeviceorientation/', $s)) {
            $score -= 10;
        }

        // --- MIB symbolic name scoring (Phase 1.5) ---
        if ($name) {
            $score += 2; // resolved name helps; keep modest so noise can't ride on it

            // Strong live power / watts
            if (preg_match(
                '/watt|activepower|realpower|totalpower|phasepower|devicepower|powerwatts|'
                . 'statuspower(?!factor)|identdevicepowerwatts|identdevicepower(?!factor|va)/',
                $s
            )) {
                $score += 14;
            }
            if (preg_match('/apparentpower|powerva|identdevicepowerva/', $s)) {
                $score += 10;
            }
            if (preg_match('/powerfactor/', $s)) {
                $score += 8;
            }
            if (preg_match('/peakcurrent|peakpower/', $s) && !preg_match('/reset|timestamp|start/', $s)) {
                $score += 8;
            }
            // APC high-value leaves
            if (preg_match('/rpdu2?phasestatuspower(?!factor)|rpduidentdevicepowerwatts|upsadvoutput/', $s)) {
                $score += 12;
            } elseif (preg_match('/rpdu2phasestatus(current|voltage|apparent|powerfactor|peakcurrent|loadstate)/', $s)) {
                $score += 10;
            } elseif (preg_match('/rpdu2phasetophase|phasetophasestatusvoltage/', $s)) {
                $score += 8;
            } elseif (preg_match('/powersupply\d?status|powersupplyalarm/', $s)) {
                $score += 6;
            }
            // Current / amps (live status only — config already filtered)
            if (preg_match('/statuscurrent|phasestatuscurrent|loadstatusload|(?<![a-z])current(?![a-z])|\bamps?\b/', $s)
                && !preg_match('/peak|threshold|config/', $s)
            ) {
                $score += 12;
            }
            // Voltage
            if (preg_match('/statusvoltage|phasestatusvoltage|phasetophase|(?<![a-z])voltage(?![a-z])/', $s)) {
                $score += 8;
            }
            if (preg_match('/\btemp|temperature\b/', $s)) {
                $score += 4;
            }
            // Environmental manager / probes (AP9340, IEM, uio, NetBotz-class under PowerNet)
            if (preg_match('/\bhumid|humidity|dewpoint|dew.?point|probe|iemstatus|iemconfig|emstatus|emconfig|uio|sensorstatus|envmon|environmental\b/', $s)) {
                $score += 14;
            }
            if (preg_match('/1\.3\.6\.1\.4\.1\.318\.1\.1\.(10|25)\./', $s)
                || str_contains($s, '1.3.6.1.4.1.318.1.1.10.')
                || str_contains($s, '1.3.6.1.4.1.318.1.1.25.')
            ) {
                $score += 10;
            }
            // Prefer phase/device totals over bulk outlet sensors
            if (preg_match('/phasestatus|identdevice|devicestatus|phasetophase/', $s)) {
                $score += 4;
            }
            // Demote high-index outlet leaves (clutter) but keep outlet #1 / table bases useful
            if (preg_match('/outlet|receptacle/', $s)) {
                if (preg_match('/outletmeteredstatus(current|power|name|state)/', $s)
                    || preg_match('/1\.3\.6\.1\.4\.1\.318\.1\.1\.26\.9\.4\.3\.1\.[3567](?:\.1)?$/', $s)
                ) {
                    $score += 8; // first-instance / named status columns → propose as table bases
                } else {
                    $score -= 6;
                }
            }
            // Rating for util %
            if (preg_match('/maxphasecurrentrating|identdevicerating|maxcurrentrating/', $s)) {
                $score += 6;
            }
            // Identity leftovers
            if (preg_match('/serial|location|contact|descr|model|firmware|version|trap|sku/', $s)
                && !preg_match('/load|power|amp|watt|current|volt/', $s)
            ) {
                $score -= 10;
            }
        } else {
            // Numeric-only: weak; need clear value heuristics
            if (str_contains($s, 'watt') || (str_contains($s, 'power') && !str_contains($s, 'factor'))) {
                $score += 3;
            }
            if (str_contains($s, 'amp') || str_contains($s, 'current')) {
                $score += 2;
            }
        }

        // Value-range heuristics — only for real numbers, never model strings
        if (!$nonMetric && $num !== null && $num >= 0) {
            $score += 1;
            if ($num > 0 && $num < 500000) {
                $score += 1;
            }
            // Plausible total watts (device totals, not tiny enums)
            if ($num >= 50 && $num <= 200000 && preg_match('/watt|power(?!factor)|statuspower/', $s)) {
                $score += 2;
            }
        }

        // Drop pure identity strings (e.g. model AP8861) even if under enterprise tree
        if ($nonMetric) {
            $metricName = (bool)preg_match(
                '/watt|activepower|realpower|apparentpower|devicepower|phasepower|powerwatts|'
                . 'statuspower|identdevicepower|phasestatus|loadstate|powersupply/',
                $s
            );
            $identityName = (bool)preg_match(
                '/serial|model|identmodel|partnumber|firmware|version|location|contact|descr|'
                . 'hostname|sku|name(?![a-z])/',
                $s
            );
            if (!$metricName || $identityName) {
                return 0;
            }
        }

        // Unnamed OIDs with no metric keyword and only tiny enum-like values → drop
        if (!$name && $num !== null && $num >= 0 && $num <= 5
            && !preg_match('/watt|power|amp|current|volt|load|temp/', $s)
        ) {
            return 0;
        }

        return max(0, $score);
    }

    private static function hintFor(string $oid, ?string $name, $raw, ?float $num): string
    {
        $hints = [];
        $s = self::nameHaystack($oid, $name);
        $nonMetric = self::isNonMetricString($raw);

        if (str_starts_with($oid, '1.3.6.1.4.1.99999.2.1')) {
            $hints[] = 'lab watts';
        }
        if (str_starts_with($oid, '1.3.6.1.4.1.99999.2.2')) {
            $hints[] = 'lab amps×10';
        }

        if ($name) {
            if (preg_match('/serial|model|identmodel|partnumber|firmware|version|location|contact|descr|name\b/', $s)
                && !preg_match('/load|power|amp|watt|current/', $s)
            ) {
                $hints[] = 'identity/config string';
            }
            if (preg_match('/powerfactor/', $s) && !preg_match('/config/', $s)) {
                $hints[] = 'MIB: power factor';
            } elseif (preg_match('/apparentpower|powerva/', $s)) {
                $hints[] = 'MIB: apparent power (VA)';
            } elseif (preg_match(
                '/watt|activepower|realpower|devicepower|phasepower|powerwatts|statuspower(?!factor)|identdevicepower(?!factor|va)/',
                $s
            )) {
                $hints[] = 'MIB: watts/power';
            }
            if (preg_match('/peakcurrent/', $s) && !preg_match('/reset|timestamp/', $s)) {
                $hints[] = 'MIB: peak current';
            }
            if (preg_match('/\bamp|current|phasestatuscurrent|loadstatusload/', $s)
                && !preg_match('/watt|power/', $s)
            ) {
                $hints[] = 'MIB: amps/current';
            }
            if (preg_match('/\bload\b|outputload|loadpercent|phasestatusload/', $s)
                && !preg_match('/watt|power|amp|current/', $s)
            ) {
                $hints[] = 'MIB: load %';
            }
            if (preg_match('/\bvolt|voltage\b/', $s)) {
                $hints[] = 'MIB: voltage';
            }
            if (preg_match('/\btemp|temperature\b/', $s)) {
                $hints[] = 'MIB: temperature';
            }
            if (preg_match('/\bhumid|humidity\b/', $s)) {
                $hints[] = 'MIB: humidity';
            }
            if (preg_match('/dewpoint|dew.?point/', $s)) {
                $hints[] = 'MIB: dew point';
            }
            if (preg_match('/iemstatus|iemconfig|emstatus|uio|probe|envmon|environmental/', $s)
                || preg_match('/1\.3\.6\.1\.4\.1\.318\.1\.1\.(10|25)\./', $oid)
            ) {
                $hints[] = 'MIB: environmental probe';
            }
            if (preg_match('/rpdu2|powernet-mib::|::apc|apc::/i', $s) || str_contains($oid, '.1.3.6.1.4.1.318.') || str_starts_with($oid, '1.3.6.1.4.1.318.')) {
                $hints[] = 'APC PowerNet';
            }
            if (preg_match('/phase/', $s) && !preg_match('/outlet/', $s)) {
                $hints[] = 'phase metric';
            }
            if (preg_match('/outlet|receptacle/', $s)) {
                $hints[] = 'outlet metric';
            }
        }

        // Value fallbacks only for real numeric samples — never for "AP8861"
        if (!$hints && !$nonMetric && $num !== null) {
            if (str_contains($oid, '.318.') && $num <= 100) {
                $hints[] = 'possible load %';
            }
            if ($num > 100 && $num < 20000) {
                $hints[] = 'possible watts';
            }
            if ($num > 0 && $num < 100) {
                $hints[] = 'possible amps or %';
            }
        }
        if ($nonMetric && !$hints) {
            $hints[] = 'non-numeric string';
        }

        return $hints ? implode(', ', $hints) : 'candidate';
    }

    /**
     * @param list<array{oid:string,name?:?string,numeric:?float,score:int,hint:string}> $candidates
     * @return array<string,string>
     */
    private static function proposeMap(array $candidates, $sysDescr): array
    {
        $map = [
            'sysDescr' => '1.3.6.1.2.1.1.1.0',
            'sysUpTime' => '1.3.6.1.2.1.1.3.0',
        ];
        $watts = null;
        $wattsScore = -1;
        $amps = null;
        $ampsX10 = null;

        // Multi-phase: same parent OID with instances .1 .2 .3 (L1/L2/L3)
        $phaseKeys = self::proposePhaseMapKeys($candidates);
        foreach ($phaseKeys as $k => $oid) {
            $map[$k] = $oid;
        }
        // Device-level VA/PF/PS + L–L voltages
        foreach (self::proposeDeviceAndLlKeys($candidates) as $k => $oid) {
            $map[$k] = $oid;
        }
        // Per-outlet table bases (walked .1…N by SnmpPoller)
        foreach (self::proposeOutletMapKeys($candidates) as $k => $oid) {
            $map[$k] = $oid;
        }

        $pick = static function (array $c) use (&$watts, &$wattsScore, &$amps, &$ampsX10): void {
            $oid = $c['oid'];
            $hint = strtolower($c['hint'] ?? '');
            $name = strtolower((string)($c['name'] ?? ''));
            $n = $c['numeric'];
            $hay = $name . ' ' . $hint;
            $sc = (int)($c['score'] ?? 0);

            if (preg_match('/config|threshold|reset|timestamp|powerfactor|powersupply|properties/', $hay)) {
                return;
            }

            // Prefer device/total power — skip pure phase-instance leaves when we already map phases
            $isPhaseLeaf = (bool)preg_match('/phasestatus|phase\d|\.phase/i', $hay)
                || (bool)preg_match('/\.[123]$/', $oid);

            $looksWatts = str_contains($hay, 'watt')
                || str_contains($hay, 'mib: watts')
                || str_contains($hay, 'identdevicepowerwatts')
                || (str_contains($hay, 'identdevicepower') && !str_contains($hay, 'factor') && !str_contains($hay, 'va'))
                || str_starts_with($oid, '1.3.6.1.4.1.99999.2.1')
                || ($n !== null && $n >= 50 && $n <= 100000 && str_contains($hint, 'possible watts'));

            if ($looksWatts) {
                if (str_contains($hay, 'outlet')) {
                    return;
                }
                if ($isPhaseLeaf && !preg_match('/device|total|ident/', $hay)) {
                    return;
                }
                // Prefer explicit *PowerWatts / ident device power over scaled rPDU2 status
                $pref = $sc;
                if (str_contains($hay, 'powerwatts') || str_contains($hay, 'identdevicepowerwatts')) {
                    $pref += 50;
                } elseif (str_contains($hay, 'identdevicepower')) {
                    $pref += 30;
                } elseif (str_contains($hay, 'devicestatuspower') && !str_contains($hay, 'peak')) {
                    $pref += 5;
                }
                if ($pref > $wattsScore) {
                    $wattsScore = $pref;
                    $watts = $oid;
                }
            }
            if ($ampsX10 === null && str_starts_with($oid, '1.3.6.1.4.1.99999.2.2')) {
                $ampsX10 = $oid;
            }
            if ($amps === null && $ampsX10 === null) {
                $looksAmps = (str_contains($hay, 'amp') || str_contains($hay, 'current') || str_contains($hint, 'mib: amps'))
                    && !str_contains($hay, 'watt')
                    && !str_contains($hay, 'power');
                $rangeAmps = $n !== null && $n > 0 && $n < 80 && str_contains($hint, 'possible amps');
                if (($looksAmps || $rangeAmps) && !str_contains($hay, 'outlet')) {
                    if ($isPhaseLeaf && !preg_match('/device|total|ident/', $hay)) {
                        return;
                    }
                    $amps = $oid;
                }
            }
        };

        // First pass: named high scores
        foreach ($candidates as $c) {
            if (!empty($c['name']) && ($c['score'] ?? 0) >= 8) {
                $pick($c);
            }
        }
        // Second pass: anything remaining
        foreach ($candidates as $c) {
            $pick($c);
        }

        // Fallbacks: highest-scoring enterprise numeric OIDs (not phase leaves if phases mapped)
        if ($watts === null) {
            foreach ($candidates as $c) {
                if ($c['numeric'] !== null && $c['numeric'] >= 20 && $c['score'] >= 5
                    && str_starts_with($c['oid'], '1.3.6.1.4.1.')) {
                    if ($phaseKeys && preg_match('/\.[123]$/', $c['oid'])) {
                        continue;
                    }
                    $hay = strtolower((string)($c['name'] ?? ''));
                    if (preg_match('/config|threshold|factor|supply/', $hay)) {
                        continue;
                    }
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
     * When Discover sees the same metric parent with instances .1 .2 .3, map to phaseN_*.
     * APC rPDU2 uses scaled keys (amps_x10, watts_hundredths_kw, …).
     *
     * @param list<array{oid:string,name?:?string,hint?:string,score?:int,numeric?:?float}> $candidates
     * @return array<string,string>
     */
    private static function proposePhaseMapKeys(array $candidates): array
    {
        /** @var array<string,array<int,array>> $byParent */
        $byParent = [];
        foreach ($candidates as $c) {
            $oid = (string)($c['oid'] ?? '');
            if (!preg_match('/^(\d+(?:\.\d+)+)\.([123])$/', $oid, $m)) {
                continue;
            }
            if (($c['score'] ?? 0) < 3) {
                continue;
            }
            $parent = $m[1];
            $idx = (int)$m[2];
            if (!isset($byParent[$parent][$idx])
                || ($c['score'] ?? 0) > ($byParent[$parent][$idx]['score'] ?? 0)
            ) {
                $byParent[$parent][$idx] = $c;
            }
        }

        $out = [];
        $families = [];
        foreach ($byParent as $parent => $idxs) {
            if (!isset($idxs[1], $idxs[2], $idxs[3])) {
                continue;
            }
            $hay = strtolower(
                (string)($idxs[1]['name'] ?? '') . ' '
                . (string)($idxs[1]['hint'] ?? '') . ' ' . $parent
            );
            if (preg_match('/outlet|receptacle|socket|config|threshold|reset|timestamp|starttime/', $hay)) {
                continue;
            }
            $kind = null; // map key suffix including scale
            $apc = (bool)preg_match('/rpdu2|powernet|1\.3\.6\.1\.4\.1\.318/', $hay . $parent);

            if (preg_match('/peakcurrent/', $hay)) {
                $kind = $apc ? 'peak_amps_x10' : 'peak_amps';
            } elseif (preg_match('/statuscurrent|phasestatuscurrent|(?<![a-z])current(?![a-z])/', $hay)
                && !preg_match('/peak|max|watt|statuspower|powerfactor|voltage/', $hay)
            ) {
                $kind = $apc ? 'amps_x10' : 'amps';
            } elseif (preg_match('/statusvoltage|phasestatusvoltage|(?<![a-z])voltage(?![a-z])/', $hay)
                && !preg_match('/peak|max|current|amp|phasetophase|lineto/', $hay)
            ) {
                $kind = 'volts';
            } elseif (preg_match('/apparentpower/', $hay)) {
                $kind = $apc ? 'va_hundredths_kw' : 'va';
            } elseif (preg_match('/powerfactor/', $hay)) {
                $kind = $apc ? 'pf_x100' : 'pf';
            } elseif (preg_match('/loadstate/', $hay)) {
                $kind = 'load_state';
            } elseif (preg_match('/statuspower(?!factor)|activepower|realpower|phasepower|(?<![a-z])watts?(?![a-z])/', $hay)
                && !preg_match('/powerfactor|apparent|reactive|current|voltage|peak/', $hay)
            ) {
                $kind = $apc ? 'watts_hundredths_kw' : 'watts';
            }
            if ($kind === null) {
                continue;
            }
            $score = ($idxs[1]['score'] ?? 0) + ($idxs[2]['score'] ?? 0) + ($idxs[3]['score'] ?? 0);
            if (preg_match('/rpdu2|phasestatus/', $hay)) {
                $score += 20;
            }
            // Base kind for uniqueness (amps vs peak_amps)
            $base = preg_replace('/_x10|_hundredths_kw|_x100$/', '', $kind) ?? $kind;
            $families[] = [
                'parent' => $parent,
                'kind' => $kind,
                'base' => $base,
                'idxs' => $idxs,
                'score' => $score,
            ];
        }

        usort($families, static fn($a, $b) => $b['score'] <=> $a['score']);
        $usedBase = [];
        foreach ($families as $fam) {
            if (isset($usedBase[$fam['base']])) {
                continue;
            }
            $usedBase[$fam['base']] = true;
            for ($i = 1; $i <= 3; $i++) {
                $out['phase' . $i . '_' . $fam['kind']] = $fam['parent'] . '.' . $i;
            }
        }
        return $out;
    }

    /**
     * Device totals (VA/PF), dual PS status, L–L voltages, phase rating.
     *
     * @param list<array{oid:string,name?:?string,hint?:string,score?:int,numeric?:?float}> $candidates
     * @return array<string,string>
     */
    private static function proposeDeviceAndLlKeys(array $candidates): array
    {
        $out = [];
        $best = []; // key => [score, oid]

        $consider = static function (string $key, array $c, int $bonus = 0) use (&$best): void {
            $sc = (int)($c['score'] ?? 0) + $bonus;
            if (!isset($best[$key]) || $sc > $best[$key][0]) {
                $best[$key] = [$sc, $c['oid']];
            }
        };

        foreach ($candidates as $c) {
            $name = strtolower((string)($c['name'] ?? ''));
            $hay = $name . ' ' . strtolower((string)($c['hint'] ?? ''));
            $oid = (string)($c['oid'] ?? '');
            if ($oid === '' || preg_match('/config|threshold|reset|timestamp|starttime/', $hay)) {
                continue;
            }

            if (preg_match('/identdevicepowerwatts|rpduidentdevicepowerwatts/', $hay)) {
                $consider('watts', $c, 80);
            }
            if (preg_match('/identdevicepowerva|powerva\.0|devicepowerva/', $hay)
                && !preg_match('/phase/', $hay)
            ) {
                $consider('va', $c, 40);
            }
            if (preg_match('/identdevicepowerfactor/', $hay)) {
                $consider('pf_x1000', $c, 40);
            } elseif (preg_match('/devicestatuspowerfactor/', $hay) && !preg_match('/phase/', $hay)) {
                $consider('pf_x100', $c, 20);
            }
            if (preg_match('/powersupply1status|rpdu2devicesstatuspowersupply1|powersupply1status/', $hay)
                || preg_match('/rpdu2devicesstatuspowersupply1status|powersupply1status/', $hay)
            ) {
                $consider('ps1_status', $c, 15);
            }
            if (str_contains($hay, 'powersupply1status') || str_contains($hay, 'power_supply1')) {
                $consider('ps1_status', $c, 15);
            }
            if (str_contains($hay, 'powersupply2status')) {
                $consider('ps2_status', $c, 15);
            }
            if (str_contains($hay, 'powersupplyalarm') && !str_contains($hay, 'phase')) {
                $consider('ps_alarm', $c, 15);
            }
            if (preg_match('/maxphasecurrentrating|loaddevmaxphaseload|identdevicerating/', $hay)) {
                $consider('phase_rated_amps', $c, 10);
            }
            if (preg_match('/voltage1to2|phasetophasestatusvoltage1to2|linetoline.*1.*2/', $hay)
                || str_contains($hay, 'voltage1to2')
            ) {
                $consider('phase_l12_volts', $c, 25);
            }
            if (str_contains($hay, 'voltage2to3')) {
                $consider('phase_l23_volts', $c, 25);
            }
            if (str_contains($hay, 'voltage3to1')) {
                $consider('phase_l31_volts', $c, 25);
            }
        }

        foreach ($best as $key => $pair) {
            $out[$key] = $pair[1];
        }
        return $out;
    }

    /**
     * Known APC PowerNet rPDU2OutletMeteredStatus column bases (walked .1…N by SnmpPoller).
     * @return array<string,string> map key => base OID
     */
    public static function apcRpdu2OutletBases(): array
    {
        return [
            'outlet_name' => '1.3.6.1.4.1.318.1.1.26.9.4.3.1.3',
            'outlet_state' => '1.3.6.1.4.1.318.1.1.26.9.4.3.1.5',
            'outlet_amps_x10' => '1.3.6.1.4.1.318.1.1.26.9.4.3.1.6',
            'outlet_watts_hundredths_kw' => '1.3.6.1.4.1.318.1.1.26.9.4.3.1.7',
        ];
    }

    /**
     * If the agent answers rPDU2 outlet current/power, add table bases to the proposed map.
     *
     * @param array<string,string> $proposed
     * @param array<string,mixed> $creds
     * @param array<string,array{raw:mixed,name?:?string}> $collected
     * @return array<string,string>
     */
    private static function injectApcOutletBases(
        array $proposed,
        string $hostPort,
        string $version,
        array $creds,
        $sysDescr,
        array $collected
    ): array {
        foreach (array_keys($proposed) as $k) {
            if (preg_match('/^outlet_(amps|watts|power|current|name|state)\b/i', (string)$k)) {
                return $proposed; // already mapped from candidates
            }
        }

        $hay = strtolower((string)$sysDescr) . ' ' . json_encode($proposed) . ' ' . implode(' ', array_keys($collected));
        $looksApc = (bool)preg_match('/apc|schneider|powernet|rpdu|1\.3\.6\.1\.4\.1\.318/', $hay);
        // Also probe when enterprise walk already saw rPDU2 phase / device OIDs
        if (!$looksApc) {
            foreach ($collected as $oid => $_) {
                if (str_starts_with((string)$oid, '1.3.6.1.4.1.318.1.1.26')
                    || str_starts_with((string)$oid, '1.3.6.1.4.1.318.1.1.12')
                ) {
                    $looksApc = true;
                    break;
                }
            }
        }
        if (!$looksApc) {
            return $proposed;
        }

        $bases = self::apcRpdu2OutletBases();
        $probeCurrent = $bases['outlet_amps_x10'];
        $ok = false;
        // Prefer already-collected first instance
        foreach ([$probeCurrent . '.1', $probeCurrent . '.1.1'] as $leaf) {
            if (isset($collected[$leaf])) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            foreach ([$probeCurrent . '.1', $probeCurrent . '.1.1'] as $leaf) {
                $v = self::snmpGet($hostPort, $version, $creds, $leaf);
                if ($v !== null && $v !== false) {
                    $ok = true;
                    break;
                }
            }
        }
        if (!$ok) {
            return $proposed;
        }
        foreach ($bases as $key => $oid) {
            if (!isset($proposed[$key])) {
                $proposed[$key] = $oid;
            }
        }
        return $proposed;
    }

    /**
     * Per-outlet table column bases for SnmpPoller::collectOutletTables.
     * Candidates are usually instance OIDs (…column.1 or …column.1.N); strip index → base.
     *
     * Keys: outlet_amps_x10, outlet_watts_hundredths_kw, outlet_name, outlet_state
     *
     * @param list<array{oid:string,name?:?string,hint?:string,score?:int,numeric?:?float}> $candidates
     * @return array<string,string>
     */
    private static function proposeOutletMapKeys(array $candidates): array
    {
        $best = []; // field => [score, baseOid]

        $consider = static function (string $field, string $baseOid, int $score) use (&$best): void {
            if ($baseOid === '') {
                return;
            }
            if (!isset($best[$field]) || $score > $best[$field][0]) {
                $best[$field] = [$score, $baseOid];
            }
        };

        // Known APC rPDU2OutletMeteredStatus* column bases (PowerNet)
        $apcBases = [
            'name' => self::apcRpdu2OutletBases()['outlet_name'],
            'state' => self::apcRpdu2OutletBases()['outlet_state'],
            'amps' => self::apcRpdu2OutletBases()['outlet_amps_x10'],
            'watts' => self::apcRpdu2OutletBases()['outlet_watts_hundredths_kw'],
        ];

        foreach ($candidates as $c) {
            $oid = (string)($c['oid'] ?? '');
            if ($oid === '') {
                continue;
            }
            $name = strtolower((string)($c['name'] ?? ''));
            $hint = strtolower((string)($c['hint'] ?? ''));
            $hay = $name . ' ' . $hint . ' ' . $oid;
            $sc = (int)($c['score'] ?? 0);

            // Must look like an outlet/receptacle metric (not phase totals)
            if (!preg_match('/outlet|receptacle|socket|rpdu2outlet/', $hay)) {
                continue;
            }
            if (preg_match('/config|threshold|reset|timestamp|starttime|peakpower|peakcurrent|energy|control|switched/', $hay)
                && !preg_match('/outletmeteredstatus/', $hay)
            ) {
                // Still allow APC metered status columns even if "status" alone matched poorly
                if (!preg_match('/outletmeteredstatus(current|power|name|state)/', $hay)
                    && !preg_match('/1\.3\.6\.1\.4\.1\.318\.1\.1\.26\.9\.4\.3\.1\.[3567]/', $oid)
                ) {
                    continue;
                }
            }

            // Strip trailing instance: .N or .module.N → column base
            $base = $oid;
            if (preg_match('/^(.+)\.1\.\d+$/', $oid, $m)) {
                $base = $m[1]; // module.outlet style leaf
            } elseif (preg_match('/^(.+)\.(\d+)$/', $oid, $m)) {
                $base = $m[1];
            }

            $field = null;
            $bonus = 0;
            if (preg_match('/outletmeteredstatuscurrent|statuscurrent|outlet.*current|(?<![a-z])current(?![a-z])/', $hay)
                && !preg_match('/peak|max|watt|power|voltage|name|state/', $hay)
            ) {
                $field = 'amps';
                $bonus = 20;
            } elseif (preg_match('/outletmeteredstatuspower|statuspower|outlet.*power|(?<![a-z])watts?(?![a-z])/', $hay)
                && !preg_match('/powerfactor|apparent|peak|current|voltage|name|state/', $hay)
            ) {
                $field = 'watts';
                $bonus = 20;
            } elseif (preg_match('/outletmeteredstatusname|statusname|outlet.*name/', $hay)) {
                $field = 'name';
                $bonus = 10;
            } elseif (preg_match('/outletmeteredstatusstate|statusstate|outlet.*loadstate|outlet.*state/', $hay)
                && !preg_match('/switched|control/', $hay)
            ) {
                $field = 'state';
                $bonus = 10;
            }

            // APC numeric path fallback by column id
            if ($field === null) {
                foreach ($apcBases as $f => $apcBase) {
                    if ($base === $apcBase || str_starts_with($oid, $apcBase . '.')) {
                        $field = $f;
                        $bonus = 40;
                        $base = $apcBase;
                        break;
                    }
                }
            }
            if ($field === null) {
                continue;
            }
            if (preg_match('/rpdu2|outletmetered|1\.3\.6\.1\.4\.1\.318/', $hay . $base)) {
                $bonus += 15;
            }
            $consider($field, $base, $sc + $bonus);
        }

        // If we found amps or watts on APC tree, fill sibling columns from known bases
        $hasApc = false;
        foreach ($best as $pair) {
            if (str_starts_with($pair[1], '1.3.6.1.4.1.318.1.1.26.9.4.3.1.')) {
                $hasApc = true;
                break;
            }
        }
        if ($hasApc || (isset($best['amps']) || isset($best['watts']))) {
            foreach ($apcBases as $f => $apcBase) {
                // Only fill siblings when at least one APC metered column was seen
                $anyApcHit = false;
                foreach ($best as $pair) {
                    if (str_starts_with($pair[1], '1.3.6.1.4.1.318.1.1.26.9.')) {
                        $anyApcHit = true;
                        break;
                    }
                }
                if (!$anyApcHit) {
                    break;
                }
                if (!isset($best[$f])) {
                    $best[$f] = [5, $apcBase];
                }
            }
        }

        $out = [];
        $apc = static function (string $baseOid): bool {
            return str_contains($baseOid, '1.3.6.1.4.1.318');
        };
        if (isset($best['amps'])) {
            $oid = $best['amps'][1];
            $out[$apc($oid) ? 'outlet_amps_x10' : 'outlet_amps'] = $oid;
        }
        if (isset($best['watts'])) {
            $oid = $best['watts'][1];
            $out[$apc($oid) ? 'outlet_watts_hundredths_kw' : 'outlet_watts'] = $oid;
        }
        if (isset($best['name'])) {
            $out['outlet_name'] = $best['name'][1];
        }
        if (isset($best['state'])) {
            $out['outlet_state'] = $best['state'][1];
        }
        return $out;
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
            'security_level' => (string)($device['snmp_v3_sec_level'] ?? ''),
            'auth_protocol' => (string)($device['snmp_v3_auth_proto'] ?? 'SHA'),
            'auth_passphrase' => (string)(Crypto::decryptQuiet($device['snmp_v3_auth_pass'] ?? null) ?? ''),
            'priv_protocol' => (string)($device['snmp_v3_priv_proto'] ?? 'AES'),
            'priv_passphrase' => (string)(Crypto::decryptQuiet($device['snmp_v3_priv_pass'] ?? null) ?? ''),
            'community' => (string)(Crypto::decryptQuiet($device['snmp_community'] ?? null) ?? 'public'),
        ];
        // Profile overrides when set (same rules as device Save)
        if (!empty($device['snmp_v3_profile_id'])) {
            try {
                $prof = Database::fetchOne(
                    'SELECT * FROM snmp_v3_profiles WHERE profile_id = ? AND is_active = 1',
                    [(int)$device['snmp_v3_profile_id']]
                );
                if ($prof) {
                    if (trim((string)($prof['security_name'] ?? '')) !== '') {
                        $creds['security_name'] = (string)$prof['security_name'];
                    }
                    if (trim((string)($prof['security_level'] ?? '')) !== '') {
                        $creds['security_level'] = (string)$prof['security_level'];
                    }
                    if (trim((string)($prof['auth_protocol'] ?? '')) !== '') {
                        $creds['auth_protocol'] = (string)$prof['auth_protocol'];
                    }
                    if (trim((string)($prof['priv_protocol'] ?? '')) !== '') {
                        $creds['priv_protocol'] = (string)$prof['priv_protocol'];
                    }
                    if (!empty($prof['auth_passphrase'])) {
                        $creds['auth_passphrase'] = (string)(Crypto::decryptQuiet((string)$prof['auth_passphrase']) ?? '');
                    }
                    if (!empty($prof['priv_passphrase'])) {
                        $creds['priv_passphrase'] = (string)(Crypto::decryptQuiet((string)$prof['priv_passphrase']) ?? '');
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
        $community = (string)(Crypto::decryptQuiet($pdu['snmp_community'] ?? null) ?? 'public');
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
            'auth_passphrase' => (string)(Crypto::decryptQuiet($pdu['snmp_auth_passphrase'] ?? null) ?? ''),
            'priv_protocol' => (string)($pdu['snmp_priv_protocol'] ?? 'AES'),
            'priv_passphrase' => (string)(Crypto::decryptQuiet($pdu['snmp_priv_passphrase'] ?? null) ?? ''),
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
                        $creds['auth_passphrase'] = (string)(Crypto::decryptQuiet((string)$prof['auth_passphrase']) ?? '');
                    }
                    if (!empty($prof['priv_passphrase'])) {
                        $creds['priv_passphrase'] = (string)(Crypto::decryptQuiet((string)$prof['priv_passphrase']) ?? '');
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
