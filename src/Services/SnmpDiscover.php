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
     * Manufacturer / family rulesets for Discover.
     * - apc:     APC PowerNet / rPDU2 / EMS (existing tuned path — do not dilute)
     * - liebert: Vertiv / Emerson / Liebert LGP cooling
     * - idrac:   Dell iDRAC / OMSA BMC
     * - default: unknown vendors — generic MIB-II only (no vendor enterprise walks)
     *
     * Keep each walk list short: every empty root burns a full SNMP timeout under IIS.
     */

    /** Shared system + interface roots (MAC / identity). */
    private const WALK_ROOTS_SYSTEM = [
        '1.3.6.1.2.1.1',                  // MIB-II system
        '1.3.6.1.2.1.2.2.1.2',            // IF-MIB ifDescr
        '1.3.6.1.2.1.2.2.1.6',            // IF-MIB ifPhysAddress
    ];

    /**
     * APC PowerNet (enterprise 318) — narrow only.
     * Never walk all of 318.1.1.10 (EMS config tables hang IIS FastCGI).
     * Classic rPDU (12.x) is required for AP78xx/AP7862-class cards that predate rPDU2.
     */
    private const WALK_ROOTS_APC = [
        '1.3.6.1.2.1.1',
        '1.3.6.1.2.1.2.2.1.2',
        '1.3.6.1.2.1.2.2.1.6',
        // Classic rPDU (AP78xx / AP7862 / many metered Zero-U) — Ident + load status
        '1.3.6.1.4.1.318.1.1.12.1',       // rPDUIdent (serial, power W, phases, voltage)
        '1.3.6.1.4.1.318.1.1.12.2.3',     // rPDULoadStatus (phase/bank amps + load state)
        // rPDU2 device + phase (AP88xx / newer firmware; empty on pure classic cards)
        '1.3.6.1.4.1.318.1.1.26.4.3',     // rPDU2DeviceStatus (incl. DeviceStatusPower)
        '1.3.6.1.4.1.318.1.1.26.6.3',     // rPDU2PhaseStatus
        '1.3.6.1.4.1.318.1.1.26.9.4',     // rPDU2 outlet metered status (narrow)
        // EMS / environmental (AP9340 etc.)
        '1.3.6.1.4.1.318.1.1.10.3.13',    // emsProbeStatus* live temp/humidity
        '1.3.6.1.4.1.318.1.1.10.3.5',     // alternate EMS probe status
        '1.3.6.1.4.1.318.1.1.10.2.3',     // IEM status probes
        '1.3.6.1.4.1.318.1.1.10.3.1',     // emsIdent (serial)
        '1.3.6.1.4.1.99999',              // ColdAisle lab
    ];

    /**
     * APC / Schneider UPS (PowerNet 318.1.1.1) — Symmetra, Smart-UPS, etc.
     * Keep narrow: full 318.1.1.1 walks are huge on modular frames.
     */
    private const WALK_ROOTS_UPS = [
        '1.3.6.1.2.1.1',
        '1.3.6.1.2.1.2.2.1.2',
        '1.3.6.1.2.1.2.2.1.6',
        '1.3.6.1.4.1.318.1.1.1.1',       // upsBasicIdent
        '1.3.6.1.4.1.318.1.1.1.2.2',     // upsAdvBattery
        '1.3.6.1.4.1.318.1.1.1.3.2',     // upsAdvInput
        '1.3.6.1.4.1.318.1.1.1.4',       // upsBasic/Adv Output
        '1.3.6.1.4.1.318.1.1.1.7.2',     // upsAdvConfig (narrow)
        '1.3.6.1.4.1.318.1.1.1.12',      // phase tables (3φ Symmetra)
    ];

    /**
     * Emerson / Liebert / Vertiv thermal (enterprise 476).
     * Empty condition roots each burn a full timeout on slow Unity cards.
     */
    private const WALK_ROOTS_LIEBERT = [
        '1.3.6.1.4.1.476.1.42.2.1',
        '1.3.6.1.4.1.476.1.42.2',
        '1.3.6.1.4.1.476.1.42.3.9.20.1.20.1.2',
        '1.3.6.1.2.1.1',
    ];

    /**
     * Dell iDRAC / OpenManage Server Administrator (enterprise 674.10892.5).
     * Narrow status/inventory trees only — never walk all of 674.
     */
    private const WALK_ROOTS_IDRAC = [
        '1.3.6.1.2.1.1',
        '1.3.6.1.2.1.2.2.1.2',
        '1.3.6.1.2.1.2.2.1.6',
        '1.3.6.1.4.1.674.10892.5.1',       // MIB-Dell-10892 system information
        '1.3.6.1.4.1.674.10892.5.4.200',   // system status / state
        '1.3.6.1.4.1.674.10892.5.4.300',   // chassis information
        '1.3.6.1.4.1.674.10892.5.4.700',   // temperature probes
        '1.3.6.1.4.1.674.10892.5.4.1100',  // power unit group
        '1.3.6.1.4.1.674.10892.5.4.1200',  // power supplies
        '1.3.6.1.4.1.674.10892.5.5.1',     // firmware inventory (narrow)
    ];

    /** Unknown manufacturer — safe generic only (no enterprise vendor trees). */
    private const WALK_ROOTS_DEFAULT = [
        '1.3.6.1.2.1.1',
        '1.3.6.1.2.1.2.2.1.2',
        '1.3.6.1.2.1.2.2.1.6',
        '1.3.6.1.4.1.99999',              // ColdAisle lab
    ];

    private const MAX_OIDS = 120;
    /** Per-request SNMP timeout (microseconds). Keep short so IIS never soft-500s. */
    private const WALK_TIMEOUT_USEC = 600_000;
    private const WALK_RETRIES = 0;
    /** Probe GET timeout (microseconds) — fail closed agents quickly. */
    private const PROBE_TIMEOUT_USEC = 600_000;
    /** Leaf exploratory GETs — shorter than probe; many will miss on wrong device type. */
    private const LEAF_TIMEOUT_USEC = 300_000;
    /** Wall-clock budget for leaf phase (seconds). */
    private const LEAF_PHASE_BUDGET_SEC = 5.0;
    /** Skip walks after this many seconds total (IIS FastCGI / PHP max_execution_time ~25s). */
    private const WALK_DEADLINE_SEC = 10.0;
    /** Cooling Discover: tighter total budget so unit 2 cannot hit 25s fatal. */
    private const COOLING_TOTAL_BUDGET_SEC = 18.0;
    private const COOLING_WALK_DEADLINE_SEC = 12.0;
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
     * @param array{
     *   family?:string,
     *   manufacturer?:string,
     *   model?:string,
     *   ruleset?:string
     * } $options
     *   family: auto|cooling|power|ems|idrac|apc|liebert|default
     *   manufacturer / model: inventory hints (prefer over sysDescr when set)
     *   ruleset: force a ruleset id (apc|liebert|idrac|default)
     * @return array{
     *   ok:bool,host:string,sysDescr:?string,candidates:list<array>,
     *   proposed_map:array<string,string>,walk_count:int,message:string,
     *   named_count:int,ruleset?:string
     * }
     */
    public static function discover(array $creds, array $options = []): array
    {
        $familyHint = strtolower(trim((string)($options['family'] ?? 'auto')));
        $allowedFamily = ['auto', 'cooling', 'power', 'ems', 'ups', 'idrac', 'apc', 'liebert', 'default', 'server_bmc'];
        if (!in_array($familyHint, $allowedFamily, true)) {
            $familyHint = 'auto';
        }
        $inventoryMfr = trim((string)($options['manufacturer'] ?? ''));
        $forcedRuleset = strtolower(trim((string)($options['ruleset'] ?? '')));
        $host = trim((string)($creds['host'] ?? ''));
        if ($host === '') {
            throw new RuntimeException('Host / IP is required for discovery.');
        }
        if (!function_exists('snmp3_real_walk') && !function_exists('snmprealwalk') && !function_exists('snmp3_get')) {
            throw new RuntimeException('PHP SNMP extension is not available.');
        }

        // Stay under PHP/IIS caps; cooling walks must self-budget (see COOLING_TOTAL_BUDGET_SEC)
        @ini_set('max_execution_time', '30');
        if (function_exists('set_time_limit')) {
            @set_time_limit(30);
        }
        $discoverStarted = microtime(true);
        $logStep = static function (string $step) use ($discoverStarted, $host): void {
            $ms = (int)round((microtime(true) - $discoverStarted) * 1000);
            App::log("SnmpDiscover [{$ms}ms] host={$host} step={$step}", 'info');
        };
        $logStep('start');

        // Match poll worker: clear MIBS autoload before any SNMP call (Windows hang risk)
        @putenv('MIBS=');

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

        // Web Discover: do NOT snmp_read_mib() large PowerNet packs in IIS (can hang).
        // Use offline text OID index only for names. Poll worker may still load MIBs.
        $mibsLoaded = 0;
        @putenv('MIBS=');
        if (class_exists('MibService')) {
            try {
                // Ensure MIB dir / env only; skip Net-SNMP file load for speed/stability
                MibService::prepareSnmpEnvironment();
            } catch (Throwable $e) {
                App::log('SnmpDiscover env prep: ' . $e->getMessage(), 'warning');
            }
        }
        @putenv('MIBS=');

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
        self::setOidOutputFormat('numeric');

        // --- Fast probe: if this fails, do NOT walk trees (IIS 500 timeout) ---
        $sysDescr = self::snmpGet(
            $hostPort,
            $version,
            $creds,
            '1.3.6.1.2.1.1.1.0',
            self::PROBE_TIMEOUT_USEC
        );
        $logStep('probe_sysDescr ' . (($sysDescr === null || $sysDescr === false) ? 'fail' : 'ok'));
        if ($sysDescr === null || $sysDescr === false) {
            $hint = $version === '3'
                ? ' Check SNMPv3 user, auth/priv protocols, passphrases, and that UDP/161 is open from the IIS server.'
                : ' Check community string and that UDP/161 is open from the IIS server.';
            throw new RuntimeException(
                'No SNMP response from ' . $hostPort . ' (sysDescr).' . $hint
            );
        }

        /** @var array<string,array{raw:mixed,name:?string,module:?string,raw_key:string}> $collected */
        $collected = [];
        $errors = [];

        // LEAF GETS FIRST — reliable and bounded (walks are optional enrichment).
        // Ruleset picks vendor-specific leaves so APC paths never pay for Dell/Liebert probes.
        $sysHay = strtolower((string)$sysDescr);
        $ruleset = self::resolveRulesetId($forcedRuleset, $familyHint, $inventoryMfr, $sysDescr);
        $coolingFocus = ($ruleset === 'liebert');
        $apcFocus = ($ruleset === 'apc');
        $idracFocus = ($ruleset === 'idrac');
        $logStep(
            'ruleset=' . $ruleset
            . ' family=' . $familyHint
            . ' mfr=' . ($inventoryMfr !== '' ? $inventoryMfr : '-')
        );

        $leafSys = [
            '1.3.6.1.2.1.1.3.0',
            '1.3.6.1.4.1.99999.2.1.0',
            '1.3.6.1.4.1.99999.2.2.0',
            '1.3.6.1.4.1.99999.2.3.0',
        ];
        // Known Liebert LGP leaves (IS-UNITY-ICOM2 identity from live SolarWinds walks + classic conditions)
        $leafLiebert = [
            '1.3.6.1.4.1.476.1.42.2.1.1.0',
            '1.3.6.1.4.1.476.1.42.2.1.2.0',
            '1.3.6.1.4.1.476.1.42.2.1.3.0',
            '1.3.6.1.4.1.476.1.42.2.1.4.0',
            '1.3.6.1.4.1.476.1.42.2.1.5.0',
            '1.3.6.1.4.1.476.1.42.2.5.1.0',
            '1.3.6.1.4.1.476.1.42.3.9.20.1.20.1.2.1.5002',
            '1.3.6.1.4.1.476.1.42.3.9.20.1.20.1.2.1.4291',
            '1.3.6.1.4.1.476.1.42.3.9.20.1.20.1.2.1.307',
            '1.3.6.1.4.1.476.1.42.3.9.20.1.20.1.2.1.3104',
            '1.3.6.1.4.1.476.1.42.3.9.20.1.20.1.2.1.3111',
            '1.3.6.1.4.1.476.1.42.3.9.20.1.20.1.2.1.4240',
            '1.3.6.1.4.1.476.1.42.3.9.20.1.20.1.2.1.4241',
            '1.3.6.1.4.1.476.1.42.3.9.20.1.20.1.2.1.5001',
            '1.3.6.1.4.1.476.1.42.3.9.20.1.20.1.2.1.5003',
            '1.3.6.1.4.1.476.1.42.3.9.20.1.20.1.2.1.1',
            '1.3.6.1.4.1.476.1.42.3.9.20.1.20.1.2.1.2',
            '1.3.6.1.4.1.476.1.42.3.9.20.1.20.1.2.1.3',
        ];
        $leafLiebertId = [
            '1.3.6.1.4.1.476.1.42.2.1.1.0',
            '1.3.6.1.4.1.476.1.42.2.1.2.0',
            '1.3.6.1.4.1.476.1.42.2.1.3.0',
            '1.3.6.1.4.1.476.1.42.2.1.5.0',
            '1.3.6.1.4.1.476.1.42.2.5.1.0',
        ];
        // APC EMS / AP9340 live status (PowerNet columns × probe index 1–4)
        $leafEms = [
            '1.3.6.1.4.1.318.1.1.10.3.13.1.1.3.1',
            '1.3.6.1.4.1.318.1.1.10.3.13.1.1.6.1',
            '1.3.6.1.4.1.318.1.1.10.3.13.1.1.3.2',
            '1.3.6.1.4.1.318.1.1.10.3.13.1.1.6.2',
            '1.3.6.1.4.1.318.1.1.10.3.13.1.1.3.3',
            '1.3.6.1.4.1.318.1.1.10.3.13.1.1.6.3',
            '1.3.6.1.4.1.318.1.1.10.3.13.1.1.3.4',
            '1.3.6.1.4.1.318.1.1.10.3.13.1.1.6.4',
            '1.3.6.1.4.1.318.1.1.10.3.13.1.1.4.1',
            '1.3.6.1.4.1.318.1.1.10.3.13.1.1.5.1',
            '1.3.6.1.4.1.318.1.1.10.2.3.2.1.4.1',
            '1.3.6.1.4.1.318.1.1.10.2.3.2.1.6.1',
            '1.3.6.1.4.1.318.1.1.10.3.5.1.1.3.1',
            '1.3.6.1.4.1.318.1.1.10.3.5.1.1.6.1',
            '1.3.6.1.4.1.318.1.1.10.3.1.1.0',
            '1.3.6.1.4.1.318.1.1.10.3.1.2.0',
        ];
        $leafPdu = [
            // Classic rPDU Ident (AP7862 / AP78xx primary tree)
            '1.3.6.1.4.1.318.1.1.12.1.1.0',   // rPDUIdentName
            '1.3.6.1.4.1.318.1.1.12.1.6.0',   // serial
            '1.3.6.1.4.1.318.1.1.12.1.7.0',   // phase rating / model rating
            '1.3.6.1.4.1.318.1.1.12.1.8.0',   // num outlets (varies)
            '1.3.6.1.4.1.318.1.1.12.1.15.0',  // L–L voltage (when present)
            '1.3.6.1.4.1.318.1.1.12.1.16.0',  // rPDUIdentDevicePowerWatts
            '1.3.6.1.4.1.318.1.1.12.1.17.0',  // power factor
            '1.3.6.1.4.1.318.1.1.12.1.18.0',  // power VA
            // Classic rPDULoadStatus — tenths of A + load state (phases 1–3, banks 4–6)
            '1.3.6.1.4.1.318.1.1.12.2.3.1.1.2.1',
            '1.3.6.1.4.1.318.1.1.12.2.3.1.1.2.2',
            '1.3.6.1.4.1.318.1.1.12.2.3.1.1.2.3',
            '1.3.6.1.4.1.318.1.1.12.2.3.1.1.2.4',
            '1.3.6.1.4.1.318.1.1.12.2.3.1.1.2.5',
            '1.3.6.1.4.1.318.1.1.12.2.3.1.1.2.6',
            '1.3.6.1.4.1.318.1.1.12.2.3.1.1.3.1',
            '1.3.6.1.4.1.318.1.1.12.2.3.1.1.3.2',
            '1.3.6.1.4.1.318.1.1.12.2.3.1.1.3.3',
            '1.3.6.1.4.1.318.1.1.12.2.1.1.0',  // max phase load rating
            '1.3.6.1.4.1.318.1.1.12.2.1.2.0',  // num phases
            // rPDU2 device + phase (newer / dual-tree cards)
            '1.3.6.1.4.1.318.1.1.26.4.3.1.5.1', // DeviceStatusPower (hundredths kW)
            '1.3.6.1.4.1.318.1.1.26.4.3.1.5.2',
            '1.3.6.1.4.1.318.1.1.26.6.3.1.5.1', // phase current tenths A
            '1.3.6.1.4.1.318.1.1.26.6.3.1.5.2',
            '1.3.6.1.4.1.318.1.1.26.6.3.1.5.3',
            '1.3.6.1.4.1.318.1.1.26.6.3.1.6.1', // phase volts
            '1.3.6.1.4.1.318.1.1.26.6.3.1.6.2',
            '1.3.6.1.4.1.318.1.1.26.6.3.1.6.3',
            '1.3.6.1.4.1.318.1.1.26.6.3.1.7.1', // phase power
            '1.3.6.1.4.1.318.1.1.26.6.3.1.7.2',
            '1.3.6.1.4.1.318.1.1.26.6.3.1.7.3',
            '1.3.6.1.4.1.318.1.1.26.9.4.3.1.6.1',
            '1.3.6.1.4.1.318.1.1.26.9.4.3.1.7.1',
            '1.3.6.1.4.1.318.1.1.26.9.4.3.1.5.1',
            '1.3.6.1.4.1.318.1.1.26.9.4.3.1.3.1',
            // UPS leaves only when not clearly a rack PDU (kept last; cheap misses)
            '1.3.6.1.4.1.318.1.1.1.4.2.3.0',
            '1.3.6.1.4.1.318.1.1.1.4.2.8.0',
            '1.3.6.1.4.1.3808.1.1.1.4.2.3.0',
            '1.3.6.1.4.1.3808.1.1.1.4.2.5.0',
        ];
        // Dell iDRAC / OMSA — identity + status + common power/thermal leaves
        // (exact leaves vary by iDRAC gen / firmware; empty GETs are cheap with LEAF_TIMEOUT)
        $leafIdrac = [
            // Identity (usually works when SNMPv3 auth is OK)
            '1.3.6.1.4.1.674.10892.5.1.3.6.0',    // systemModelName
            '1.3.6.1.4.1.674.10892.5.1.3.12.0',   // systemServiceTag
            '1.3.6.1.4.1.674.10892.5.1.3.13.0',   // systemExpressServiceCode (NOT watts)
            '1.3.6.1.4.1.674.10892.5.1.3.2.0',
            // Overall health rollup (often 1=other … 3=ok)
            '1.3.6.1.4.1.674.10892.5.2.1.0',
            '1.3.6.1.4.1.674.10892.5.4.200.10.1.4.1',  // systemStateChassisStatus
            '1.3.6.1.4.1.674.10892.5.4.200.10.1.21.1', // power supply status combined
            '1.3.6.1.4.1.674.10892.5.4.200.10.1.24.1', // cooling / fan rollup
            // Instantaneous power (Watts) — common on iDRAC with power monitoring
            '1.3.6.1.4.1.674.10892.5.4.600.30.1.6.1',  // amperageReading
            '1.3.6.1.4.1.674.10892.5.4.600.20.1.6.1',  // powerReading (watts)
            '1.3.6.1.4.1.674.10892.5.4.200.10.1.42.1', // systemStatePowerConsumption
            // Temperature probes (probe index 1–4)
            '1.3.6.1.4.1.674.10892.5.4.700.20.1.6.1',
            '1.3.6.1.4.1.674.10892.5.4.700.20.1.6.2',
            '1.3.6.1.4.1.674.10892.5.4.700.20.1.8.1',
            // Power supplies
            '1.3.6.1.4.1.674.10892.5.4.1200.10.1.5.1',
            '1.3.6.1.4.1.674.10892.5.4.1200.10.1.5.2',
            '1.3.6.1.4.1.674.10892.5.4.1100.50.1.5.1',
        ];

        // EMS vs rPDU leaf order inside APC ruleset (preserve prior tuning)
        $looksEms = $apcFocus && (bool)preg_match(
            '/ap9340|environmental|ems|iem|netbotz|temp|humid|sensor/i',
            $sysHay
        );
        // MN:AP7862 / apc_hw02_rpdu_*.bin / "Rack PDU" — model tokens often omit the word "PDU"
        $looksPdu = $apcFocus && (bool)preg_match(
            '/rpdu|rack.?pdu|switched.?rack|metered|\bpdu\b|'
            . '\bap7\d{3}\b|\bap8\d{3}\b|\bap9\d{3}\b|'
            . 'mn:\s*ap[789]\d{3}|hw02_rpdu|hw05_rpdu|aos_.*rpdu/i',
            $sysHay
        );
        if ($familyHint === 'ems') {
            $looksEms = true;
            $looksPdu = false;
        } elseif ($familyHint === 'power') {
            $looksPdu = true;
        }

        if ($coolingFocus) {
            $leafGets = array_merge($leafSys, $leafLiebertId);
        } elseif ($idracFocus) {
            $leafGets = array_merge($leafSys, $leafIdrac);
        } elseif ($apcFocus) {
            if ($looksEms && !$looksPdu) {
                $leafGets = array_merge($leafSys, $leafEms, $leafPdu);
            } elseif ($looksPdu && !$looksEms) {
                $leafGets = array_merge($leafSys, $leafPdu, $leafEms);
            } else {
                // Classic APC auto: EMS first (cheap if present), then PDU
                $leafGets = array_merge($leafSys, $leafEms, $leafPdu);
            }
        } else {
            // default ruleset — no vendor enterprise leaf thrash
            $leafGets = $leafSys;
        }

        $leafStarted = microtime(true);
        $leafHits = 0;
        $leafLiebertHits = 0;
        $emsTempHits = 0;
        $emsHumHits = 0;
        // AP7862-class needs many classic rPDU leaf GETs (Ident + 6 load indexes)
        $leafBudget = $coolingFocus ? 4.0 : self::LEAF_PHASE_BUDGET_SEC;
        if ($apcFocus && $looksPdu) {
            $leafBudget = 9.0;
        }
        $totalBudget = $coolingFocus ? self::COOLING_TOTAL_BUDGET_SEC : 22.0;
        foreach ($leafGets as $oid) {
            if (isset($collected[$oid])) {
                continue;
            }
            if (count($collected) >= self::MAX_OIDS) {
                break;
            }
            if ((microtime(true) - $discoverStarted) >= $totalBudget) {
                $logStep('total_budget_exceeded_at_leaf');
                break;
            }
            if ((microtime(true) - $leafStarted) >= $leafBudget) {
                $logStep('leaf_budget_exceeded hits=' . $leafHits);
                break;
            }
            // Enough EMS live metrics — stop thrashing on PDU OIDs
            if (!$coolingFocus && $emsTempHits >= 1 && $emsHumHits >= 1 && $leafHits >= 3
                && (microtime(true) - $leafStarted) > 2.0
            ) {
                $logStep('leaf_early_stop ems_live hits=' . $leafHits);
                break;
            }
            // Identity leaves are enough for cooling — skip remaining empty condition GETs
            if ($coolingFocus && $leafLiebertHits >= 2) {
                $logStep('leaf_early_stop liebert hits=' . $leafLiebertHits);
                break;
            }
            $v = self::snmpGet($hostPort, $version, $creds, $oid, self::LEAF_TIMEOUT_USEC);
            if ($v !== null && $v !== false) {
                $collected[$oid] = [
                    'raw' => $v,
                    'name' => null,
                    'module' => null,
                    'raw_key' => $oid,
                ];
                $leafHits++;
                if (str_starts_with($oid, '1.3.6.1.4.1.476.')) {
                    $leafLiebertHits++;
                }
                if (str_contains($oid, '10.3.13.1.1.3.') || str_contains($oid, '10.2.3.2.1.4.')
                    || str_contains($oid, '10.3.5.1.1.3.')
                ) {
                    $emsTempHits++;
                }
                if (str_contains($oid, '10.3.13.1.1.6.') || str_contains($oid, '10.2.3.2.1.6.')
                    || str_contains($oid, '10.3.5.1.1.6.')
                ) {
                    $emsHumHits++;
                }
            }
        }
        $logStep('leaf_gets collected=' . count($collected) . ' hits=' . $leafHits
            . ' liebertLeaf=' . $leafLiebertHits
            . ' emsT=' . $emsTempHits . ' emsH=' . $emsHumHits);

        // Narrow walks only if still needed and under wall-clock deadline
        $elapsed = microtime(true) - $discoverStarted;
        $haveEmsLive = ($emsTempHits + $emsHumHits) >= 2;
        $liebertHits = 0;
        foreach ($collected as $oidKey => $_) {
            if (str_starts_with((string)$oidKey, '1.3.6.1.4.1.476.')) {
                $liebertHits++;
            }
        }
        $walkDeadline = $coolingFocus ? self::COOLING_WALK_DEADLINE_SEC : self::WALK_DEADLINE_SEC;
        $walkRootStats = [];
        if ($haveEmsLive && !$coolingFocus) {
            $logStep('walks_skipped have_ems_live elapsed=' . round($elapsed, 2));
        } elseif ($coolingFocus && $liebertHits >= 2) {
            // Identity already from leaf GETs — only need a short system walk if missing
            $logStep('walks_minimal have_liebert_live hits=' . $liebertHits);
            if ($elapsed < $walkDeadline && count($collected) < self::MAX_OIDS) {
                self::setOidOutputFormat('numeric');
                $walkRootStats = self::collectWalks(
                    $hostPort,
                    $version,
                    $creds,
                    $collected,
                    $errors,
                    ['1.3.6.1.2.1.1'],
                    $discoverStarted,
                    $totalBudget
                );
            }
        } elseif ($liebertHits >= 6 && !$coolingFocus) {
            $logStep('walks_skipped have_liebert_live hits=' . $liebertHits);
        } elseif ($elapsed < $walkDeadline && count($collected) < self::MAX_OIDS
            && (microtime(true) - $discoverStarted) < $totalBudget
        ) {
            self::setOidOutputFormat('numeric');
            $roots = self::walkRootsForRuleset($ruleset);
            $walkRootStats = self::collectWalks(
                $hostPort,
                $version,
                $creds,
                $collected,
                $errors,
                $roots,
                $discoverStarted,
                $totalBudget
            );
            $logStep('walks collected=' . count($collected) . ' errors=' . count($errors)
                . ' roots=' . $ruleset);
        } else {
            $logStep('walks_skipped elapsed=' . round($elapsed, 2));
        }

        // Recount enterprise after walks
        $liebertHits = 0;
        $enterpriseHits = 0;
        foreach ($collected as $oidKey => $_) {
            $ok = (string)$oidKey;
            if (str_starts_with($ok, '1.3.6.1.4.1.476')) {
                $liebertHits++;
            }
            if (str_starts_with($ok, '1.3.6.1.4.1.') && !str_starts_with($ok, '1.3.6.1.4.1.99999')) {
                $enterpriseHits++;
            }
        }

        // Always keep sysDescr in collection for scoring
        if (!isset($collected['1.3.6.1.2.1.1.1.0']) && $sysDescr !== null && $sysDescr !== false) {
            $collected['1.3.6.1.2.1.1.1.0'] = [
                'raw' => $sysDescr,
                'name' => null,
                'module' => null,
                'raw_key' => '1.3.6.1.2.1.1.1.0',
            ];
        }

        if (!$collected && ($sysDescr === null || $sysDescr === false)) {
            $detail = $errors ? implode('; ', array_slice($errors, 0, 3)) : 'No response';
            throw new RuntimeException('SNMP discovery failed: ' . $detail);
        }

        // Offline MIB text index for names (no snmp_read_mib) — cap time
        $indexSize = 0;
        if (class_exists('MibService') && (microtime(true) - $discoverStarted) < 18.0) {
            try {
                $indexSize = MibService::oidIndexSize();
            } catch (Throwable $e) {
                App::log('SnmpDiscover OID index: ' . $e->getMessage(), 'warning');
                $indexSize = 0;
            }
        }
        $logStep('index_size=' . $indexSize);

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
        // Liebert ruleset: inject LGP identity OIDs from full collection
        if ($coolingFocus) {
            foreach ([
                '1.3.6.1.4.1.476.1.42.2.1.1.0' => 'lgp_manufacturer',
                '1.3.6.1.4.1.476.1.42.2.1.2.0' => 'lgp_model',
                '1.3.6.1.4.1.476.1.42.2.1.3.0' => 'lgp_version',
                '1.3.6.1.4.1.476.1.42.2.1.5.0' => 'lgp_firmware',
            ] as $loid => $lkey) {
                if (isset($collected[$loid]) && !isset($proposed[$lkey])) {
                    $proposed[$lkey] = $loid;
                }
            }
        }
        // iDRAC ruleset: surface common Dell identity leaves if present
        if ($idracFocus) {
            foreach ([
                '1.3.6.1.4.1.674.10892.5.1.3.12.0' => 'service_tag',
                '1.3.6.1.4.1.674.10892.5.1.3.6.0' => 'system_model',
            ] as $loid => $lkey) {
                if (isset($collected[$loid]) && !isset($proposed[$lkey])) {
                    $proposed[$lkey] = $loid;
                }
            }
        }
        // APC ruleset only: seed classic rPDU / rPDU2 load keys for AP78xx when Discover
        // only saw a sparse walk (common on old AOS 3.9 + NMC when rPDU2 trees are empty).
        if ($apcFocus) {
            $proposed = self::injectApcClassicRpduMap($proposed, (string)$sysDescr, $collected);
            $proposed = self::injectApcOutletBases($proposed, $hostPort, $version, $creds, $sysDescr, $collected);
        }

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

        // Management NIC MAC from IF-MIB ifPhysAddress (when walk included interface table)
        $macHit = self::extractMacFromCollected($collected);
        if ($macHit) {
            $proposed['mac_address'] = $macHit['oid'];
            array_unshift($scored, [
                'oid' => $macHit['oid'],
                'name' => $macHit['name'] ?? 'ifPhysAddress',
                'module' => $macHit['module'] ?? 'IF-MIB',
                'value' => $macHit['value'],
                'numeric' => null,
                'score' => 98,
                'hint' => 'Management MAC → mac_address field',
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
            if ($macHit) {
                $msg .= '; MAC ' . $macHit['value'];
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

        $msg .= ' Ruleset: ' . $ruleset . '.';
        $msg .= ' Enterprise objects: ' . (int)$enterpriseHits
            . ' (Liebert/Emerson 476: ' . (int)$liebertHits . ').';
        $lgpIdentity = 0;
        $lgpConditions = 0;
        foreach (array_keys($collected) as $oidKey) {
            $o = (string)$oidKey;
            if (str_starts_with($o, '1.3.6.1.4.1.476.1.42.2')) {
                $lgpIdentity++;
            }
            if (str_starts_with($o, '1.3.6.1.4.1.476.1.42.3')) {
                $lgpConditions++;
            }
        }
        if ($coolingFocus && $liebertHits === 0) {
            $msg .= ' Auth works (sysDescr) but enterprise 1.3.6.1.4.1.476 returned nothing — '
                . 'use the same community as SolarWinds (e.g. SGMCLAN) for v2c, or fix VACM/AgentX for LGP. '
                . 'Condition tables (…476.1.42.3…) are often empty on IS-UNITY-ICOM2 even when identity (…42.2…) works.';
            if ($errors) {
                $msg .= ' Walk errors: ' . implode('; ', array_slice($errors, 0, 3)) . '.';
            }
        } elseif ($coolingFocus && $lgpIdentity > 0 && $lgpConditions === 0) {
            $msg .= ' LGP product identity present (…476.1.42.2…, e.g. Vertiv IS-UNITY-ICOM2) but '
                . 'condition/measurement tables (…476.1.42.3…) are empty — no supply/return temps over SNMP until the card exposes them.';
        } elseif ($mibsLoaded > 0 && $namedCount === 0 && $indexSize === 0) {
            $msg .= ' Could not parse OBJECT-TYPE assignments from uploaded MIBs.';
        } elseif ($indexSize > 0 && $namedCount === 0 && $enterpriseHits === 0) {
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
            'ruleset' => $ruleset,
            'candidates' => $candidates,
            'proposed_map' => $safeProposed,
            'diagnostics' => [
                'family' => $familyHint,
                'ruleset' => $ruleset,
                'manufacturer' => $inventoryMfr,
                'cooling_focus' => $coolingFocus,
                'apc_focus' => $apcFocus,
                'idrac_focus' => $idracFocus,
                'liebert_objects' => $liebertHits,
                'enterprise_objects' => $enterpriseHits,
                'walk_roots' => $walkRootStats,
                'walk_errors' => array_slice($errors, 0, 8),
            ],
            'serial_no' => isset($serialHit['value']) ? self::utf8Safe((string)$serialHit['value']) : null,
            'serial_oid' => $serialHit['oid'] ?? null,
            'mac_address' => isset($macHit['value']) ? self::utf8Safe((string)$macHit['value']) : null,
            'mac_oid' => $macHit['oid'] ?? null,
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

    /**
     * Normalize SNMP ifPhysAddress (Hex-STRING, binary, or text) to AA:BB:CC:DD:EE:FF.
     * @param mixed $raw
     */
    public static function cleanMacValue($raw): ?string
    {
        if ($raw === null || $raw === false) {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            return null;
        }
        $s = is_string($raw) ? $raw : (string)$raw;
        // Binary 6-octet MAC
        if (strlen($s) === 6 && !preg_match('/^[\x20-\x7E]+$/', $s)) {
            $parts = [];
            for ($i = 0; $i < 6; $i++) {
                $parts[] = sprintf('%02X', ord($s[$i]));
            }
            $mac = implode(':', $parts);
            return self::isUsableMac($mac) ? $mac : null;
        }
        // Hex-STRING: AA BB CC DD EE FF  or  AA:BB:…  or  AABBCCDDEEFF
        if (preg_match_all('/[0-9A-Fa-f]{2}/', $s, $m) && count($m[0]) >= 6) {
            $bytes = array_slice($m[0], 0, 6);
            $mac = strtoupper(implode(':', $bytes));
            return self::isUsableMac($mac) ? $mac : null;
        }
        return null;
    }

    public static function isUsableMac(string $mac): bool
    {
        $mac = strtoupper(trim($mac));
        if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
            return false;
        }
        if ($mac === '00:00:00:00:00:00' || $mac === 'FF:FF:FF:FF:FF:FF') {
            return false;
        }
        return true;
    }

    /**
     * Pick management-ish ifPhysAddress from a discover walk collection.
     *
     * @param array<string,array{value?:mixed,name?:?string,module?:?string}> $collected
     * @return array{oid:string,value:string,name?:string,module?:string}|null
     */
    public static function extractMacFromCollected(array $collected): ?array
    {
        /** @var list<array{oid:string,value:string,name:string,module:string,score:int,ifIndex:string}> $hits */
        $hits = [];
        $descrByIndex = [];
        foreach ($collected as $oid => $row) {
            $oid = ltrim((string)$oid, '.');
            if (preg_match('/(?:^|\.)1\.3\.6\.1\.2\.1\.2\.2\.1\.2\.(\d+)$/', $oid, $m)
                || preg_match('/ifDescr\.(\d+)$/i', $oid, $m)
            ) {
                $descrByIndex[$m[1]] = strtolower(trim((string)($row['value'] ?? '')));
            }
        }
        foreach ($collected as $oid => $row) {
            $oid = ltrim((string)$oid, '.');
            $ifIndex = null;
            if (preg_match('/(?:^|\.)1\.3\.6\.1\.2\.1\.2\.2\.1\.6\.(\d+)$/', $oid, $m)) {
                $ifIndex = $m[1];
            } elseif (preg_match('/ifPhysAddress\.(\d+)$/i', $oid, $m)) {
                $ifIndex = $m[1];
            } else {
                $nm = strtolower((string)($row['name'] ?? ''));
                if ($nm !== '' && (str_contains($nm, 'ifphysaddress') || $nm === 'ifphysaddress')) {
                    if (preg_match('/\.(\d+)$/', $oid, $m2)) {
                        $ifIndex = $m2[1];
                    }
                }
            }
            if ($ifIndex === null) {
                continue;
            }
            $mac = self::cleanMacValue($row['value'] ?? null);
            if ($mac === null) {
                continue;
            }
            $descr = $descrByIndex[$ifIndex] ?? '';
            $score = 10;
            if ($descr !== '' && preg_match('/\b(eth|enet|lan|mgmt|management|network|gig|ge|xe|fm)\b/i', $descr)) {
                $score += 40;
            }
            if ($descr !== '' && preg_match('/\b(lo|loopback|sit|tun|null|internal)\b/i', $descr)) {
                $score -= 50;
            }
            // Prefer lower ifIndex as weak tie-break (often eth0 = 1 or 2)
            $score += max(0, 5 - (int)$ifIndex);
            $hits[] = [
                'oid' => $oid,
                'value' => $mac,
                'name' => (string)($row['name'] ?? 'ifPhysAddress'),
                'module' => (string)($row['module'] ?? 'IF-MIB'),
                'score' => $score,
                'ifIndex' => $ifIndex,
            ];
        }
        if (!$hits) {
            return null;
        }
        usort($hits, static fn($a, $b) => ($b['score'] <=> $a['score']) ?: ((int)$a['ifIndex'] <=> (int)$b['ifIndex']));
        $best = $hits[0];
        if ($best['score'] < 0) {
            return null;
        }
        return [
            'oid' => $best['oid'],
            'value' => $best['value'],
            'name' => $best['name'],
            'module' => $best['module'],
        ];
    }

    /**
     * Write MAC onto PDU when empty (does not overwrite a user-entered MAC).
     * @return bool true if the row was updated
     */
    public static function applyMacToPduIfEmpty(int $pduId, ?string $mac): bool
    {
        $mac = self::cleanMacValue($mac);
        if ($mac === null || $mac === '' || $pduId < 1) {
            return false;
        }
        try {
            $row = Database::fetchOne('SELECT mac_address FROM pdus WHERE pdu_id = ?', [$pduId]);
            if (!$row) {
                // Column may not exist yet
                return false;
            }
            $cur = trim((string)($row['mac_address'] ?? ''));
            if ($cur !== '') {
                return false;
            }
            Database::update('pdus', ['mac_address' => $mac], 'pdu_id = :id', [':id' => $pduId]);
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
     * @param list<string>|null $roots walk roots (default = default ruleset)
     * @return list<array{root:string,ok:bool,added:int,error:?string}>
     */
    private static function collectWalks(
        string $hostPort,
        string $version,
        array $creds,
        array &$collected,
        array &$errors,
        ?array $roots = null,
        ?float $startedAt = null,
        ?float $budgetSec = null
    ): array {
        $consecutiveFails = 0;
        $rootList = $roots ?? self::WALK_ROOTS_DEFAULT;
        $stats = [];
        $startedAt = $startedAt ?? microtime(true);
        $budgetSec = $budgetSec ?? 20.0;
        foreach ($rootList as $root) {
            if (count($collected) >= self::MAX_OIDS) {
                break;
            }
            if ((microtime(true) - $startedAt) >= $budgetSec) {
                $errors[] = 'walk_budget_exceeded after ' . round(microtime(true) - $startedAt, 1) . 's';
                break;
            }
            // After several empty trees, stop — agent already answered probe; further
            // enterprise branches often just burn IIS request time.
            $failLimit = 3;
            if ($consecutiveFails >= $failLimit && count($collected) > 3) {
                break;
            }
            try {
                $walk = self::snmpWalk($hostPort, $version, $creds, $root);
                $before = count($collected);
                foreach ($walk as $key => $val) {
                    $parsed = self::parseOidKey((string)$key);
                    $oid = self::resolveWalkOid($parsed['oid'], (string)$root);
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
                        $stats[] = [
                            'root' => $root,
                            'ok' => true,
                            'added' => count($collected) - $before,
                            'error' => null,
                        ];
                        return $stats;
                    }
                }
                $added = count($collected) - $before;
                $stats[] = ['root' => $root, 'ok' => true, 'added' => $added, 'error' => null];
                if ($added === 0) {
                    $consecutiveFails++;
                } else {
                    $consecutiveFails = 0;
                }
            } catch (Throwable $e) {
                $err = $e->getMessage();
                $errors[] = $root . ': ' . $err;
                $stats[] = ['root' => $root, 'ok' => false, 'added' => 0, 'error' => $err];
                $consecutiveFails++;
            }
        }
        return $stats;
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

        // Prefer the longest dotted numeric run (avoids "3.0" / "9.1.4.1" from partial matches)
        if (preg_match_all('/(\d+(?:\.\d+)+)/', $rawKey, $all) && !empty($all[1])) {
            $best = '';
            foreach ($all[1] as $cand) {
                $cand = ltrim((string)$cand, '.');
                if (strlen($cand) > strlen($best)) {
                    $best = $cand;
                }
            }
            $oid = $best;
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

    /**
     * When walk() returns relative instance keys, join to the walk root.
     */
    private static function resolveWalkOid(string $oid, string $root): string
    {
        $oid = ltrim(trim($oid), '.');
        $root = ltrim(trim($root), '.');
        if ($oid === '' || $root === '') {
            return $oid;
        }
        if (str_starts_with($oid, '1.3.6.1.') || str_starts_with($oid, '1.3.6.')) {
            return $oid;
        }
        // Relative under root (e.g. root …1.2.1.1 + "3.0" → …1.2.1.1.3.0)
        if (preg_match('/^\d+(?:\.\d+)*$/', $oid)) {
            return $root . '.' . $oid;
        }
        return $oid;
    }

    /** Fallback when MibService is unavailable. */
    private static function normalizeNumericOidLocal(string $oid): string
    {
        $oid = ltrim(trim($oid), '.');
        if (preg_match('/^3\.6\.1(?:\.|$)/', $oid)) {
            return '1.' . $oid;
        }
        // Common MIB-2 system tail fragments from broken walk keys
        if (preg_match('/^(?:1\.)?([3-9](?:\.\d+)+)$/', $oid, $m)
            && !str_starts_with($oid, '1.3.6')
        ) {
            $tail = $m[1];
            if (str_starts_with($tail, '3.') || $tail === '3'
                || str_starts_with($tail, '8.') || str_starts_with($tail, '9.')
            ) {
                return '1.3.6.1.2.1.1.' . $tail;
            }
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

    private static function snmpGet(
        string $hostPort,
        string $version,
        array $creds,
        string $oid,
        ?int $timeoutUsec = null
    ) {
        $timeout = $timeoutUsec ?? self::WALK_TIMEOUT_USEC;
        try {
            if ($version === '3' && function_exists('snmp3_get')) {
                $sec = self::secLevel($creds);
                $authProto = self::normalizeSnmpProtocol((string)($creds['auth_protocol'] ?? 'SHA'), 'auth');
                $privProto = self::normalizeSnmpProtocol((string)($creds['priv_protocol'] ?? 'AES'), 'priv');
                $user = (string)($creds['security_name'] ?? '');
                $authPass = (string)($creds['auth_passphrase'] ?? '');
                $privPass = (string)($creds['priv_passphrase'] ?? '');
                $r = @snmp3_get(
                    $hostPort, $user, $sec, $authProto, $authPass, $privProto, $privPass, $oid,
                    $timeout, self::WALK_RETRIES
                );
                return ($r === false) ? null : $r;
            }
            // v1 and v2c — prefer matching API; snmp2_* often works for v1 communities
            $community = (string)($creds['community'] ?? 'public');
            if ($version === '1' && function_exists('snmpget')) {
                $r = @snmpget($hostPort, $community, $oid, $timeout, self::WALK_RETRIES);
                return ($r === false) ? null : $r;
            }
            if (function_exists('snmp2_get')) {
                $r = @snmp2_get($hostPort, $community, $oid, $timeout, self::WALK_RETRIES);
                return ($r === false) ? null : $r;
            }
            if (function_exists('snmpget')) {
                $r = @snmpget($hostPort, $community, $oid, $timeout, self::WALK_RETRIES);
                return ($r === false) ? null : $r;
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
        $root = ltrim($root, '.');
        // Keep walks short — PHP SNMP walk() can ignore long timeouts on dead Unity branches
        $timeout = self::WALK_TIMEOUT_USEC;
        if (preg_match('/^1\.3\.6\.1\.4\.1\.476\.1\.42\.2/', $root)) {
            $timeout = 800_000; // identity: small tree
        } elseif (preg_match('/^1\.3\.6\.1\.4\.1\.476/', $root)) {
            $timeout = 500_000; // empty condition roots: fail fast
        }
        try {
            // Prefer SNMP class: supports SNMPv3 context + bounded timeouts
            if (class_exists('SNMP')) {
                $ver = match (strtolower($version)) {
                    '1' => constant('SNMP::VERSION_1'),
                    '3' => constant('SNMP::VERSION_3'),
                    default => constant('SNMP::VERSION_2c'),
                };
                $communityOrUser = ($version === '3')
                    ? (string)($creds['security_name'] ?? '')
                    : (string)($creds['community'] ?? 'public');
                /** @var \SNMP $sess */
                $sess = new \SNMP($ver, $hostPort, $communityOrUser ?: 'public', $timeout, self::WALK_RETRIES);
                if (defined('SNMP_VALUE_PLAIN')) {
                    $sess->valueretrieval = constant('SNMP_VALUE_PLAIN');
                }
                $sess->exceptions_enabled = 0;
                // Cap OIDs per walk when supported (limits hang depth on slow agents)
                try {
                    $sess->max_oids = 40;
                } catch (Throwable $e) {
                    // ignore
                }
                if (defined('SNMP_OID_OUTPUT_NUMERIC')) {
                    $sess->oid_output_format = constant('SNMP_OID_OUTPUT_NUMERIC');
                }
                if ($version === '3') {
                    $sec = self::secLevel($creds);
                    $authProto = self::normalizeSnmpProtocol((string)($creds['auth_protocol'] ?? 'SHA'), 'auth');
                    $privProto = self::normalizeSnmpProtocol((string)($creds['priv_protocol'] ?? 'AES'), 'priv');
                    $sess->setSecurity(
                        $sec,
                        $authProto,
                        (string)($creds['auth_passphrase'] ?? ''),
                        $privProto,
                        (string)($creds['priv_passphrase'] ?? ''),
                        (string)($creds['context'] ?? '')
                    );
                }
                $walked = @$sess->walk($root, true);
                if (is_object($sess)) {
                    @$sess->close();
                }
                if (is_array($walked) && $walked !== []) {
                    return $walked;
                }
                throw new RuntimeException('empty walk (no objects — ACL, wrong tree, or end of MIB)');
            }

            if ($version === '3' && function_exists('snmp3_real_walk')) {
                $sec = self::secLevel($creds);
                $authProto = self::normalizeSnmpProtocol((string)($creds['auth_protocol'] ?? 'SHA'), 'auth');
                $privProto = self::normalizeSnmpProtocol((string)($creds['priv_protocol'] ?? 'AES'), 'priv');
                $user = (string)($creds['security_name'] ?? '');
                $authPass = (string)($creds['auth_passphrase'] ?? '');
                $privPass = (string)($creds['priv_passphrase'] ?? '');
                $result = @snmp3_real_walk(
                    $hostPort, $user, $sec, $authProto, $authPass, $privProto, $privPass, $root,
                    $timeout, self::WALK_RETRIES
                );
            } else {
                $community = (string)($creds['community'] ?? 'public');
                if ($version === '1' && function_exists('snmprealwalk')) {
                    $result = @snmprealwalk($hostPort, $community, $root, $timeout, self::WALK_RETRIES);
                } elseif (function_exists('snmp2_real_walk')) {
                    $result = @snmp2_real_walk($hostPort, $community, $root, $timeout, self::WALK_RETRIES);
                } elseif (function_exists('snmprealwalk')) {
                    $result = @snmprealwalk($hostPort, $community, $root, $timeout, self::WALK_RETRIES);
                }
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Walk failed for ' . $root . ': ' . $e->getMessage());
        }
        if ($result === false || !is_array($result) || $result === []) {
            throw new RuntimeException('Walk failed for ' . $root . ' (empty or no response)');
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
    /**
     * Normalize auth/priv protocol names for PHP snmp3_* / SNMP::setSecurity.
     * Public so SnmpPoller uses the same mapping as Discover (SHA-256 → SHA256, etc.).
     */
    public static function normalizeSnmpProtocol(string $proto, string $kind): string
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
     * Resolve SNMPv3 security level (shared by Discover + Poll).
     */
    public static function resolveSecLevel(array $creds): string
    {
        return self::secLevel($creds);
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

        // Config / write / audit — never useful as map candidates in the UI list.
        // APC EMS names are camelCase (emsProbeConfig…Thresh) so match without \bconfig\b.
        if (preg_match(
            '/config|thresh|threshold|nearoverload|overloadpower|lowload|'
            . 'reset|timestamp|starttime|start_time|'
            . 'orientation|displayorientation|hardwarerev|firmwarerev|'
            . 'identmodel|identserial|identname|partnumber|'
            . 'trap|notification|control\b|initiate|'
            . 'phaseconfig|deviceconfig|outletconfig|'
            . 'index\b|numphases|numoutlets|module\b|'
            . 'peakpowerstart|peakcurrentstart|peakpowerreset|peakcurrentreset|'
            . 'probeconfig|highhum|lowhum|maxtemp|mintemp|maxhum|minhum/',
            $s
        )) {
            // Keep live *status* temp/humidity (not config thresh)
            if (preg_match('/status.*(temp|humid|dew)|probe.*status.*(temp|humid)|currenttemp|currenthumid|sensorstatus(temp|humid)/', $s)
                && !preg_match('/config|thresh/', $s)
            ) {
                return false;
            }
            // Keep useful rating / max phase current (not a threshold alarm point)
            if (preg_match('/maxphasecurrentrating|identdevicerating|devicerating|maxcurrentrating/', $s)
                && !preg_match('/threshold|thresh|near|overload|lowload|reset/', $s)
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

        // Prefer enterprise power / thermal trees
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
        // Emerson / Liebert / Vertiv LGP (thermal)
        if (str_starts_with($oid, '1.3.6.1.4.1.476.')) {
            $score += 10;
        }
        if (str_starts_with($oid, '1.3.6.1.4.1.476.1.42.3.9.20.')) {
            $score += 12; // condition present-value tables
        }
        // Known DS/iCOM supply / return condition IDs
        if (preg_match('/1\.3\.6\.1\.4\.1\.476\.1\.42\.3\.9\.20\.1\.20\.1\.2\.1\.(5002|4291|5001|5003)$/', $oid)) {
            $score += 20;
        }
        // Dell iDRAC / OMSA (enterprise 674.10892.5)
        $isDellId = str_starts_with($oid, '1.3.6.1.4.1.674.10892.5.1.3.'); // service tag / model / asset
        $isDellPower = (bool)preg_match('/^1\.3\.6\.1\.4\.1\.674\.10892\.5\.4\.(600|1100|1200)\./', $oid)
            || str_contains($oid, '1.3.6.1.4.1.674.10892.5.4.200.10.1.42');
        $isDellTemp = str_starts_with($oid, '1.3.6.1.4.1.674.10892.5.4.700.');
        $isDellStatus = str_starts_with($oid, '1.3.6.1.4.1.674.10892.5.4.200.')
            || str_starts_with($oid, '1.3.6.1.4.1.674.10892.5.2.');
        if (str_starts_with($oid, '1.3.6.1.4.1.674.10892.5.')) {
            $score += 4;
        }
        if ($isDellId) {
            // Identity only — never treat Express Service Code / asset numbers as watts
            $score -= 12;
        }
        if ($isDellPower) {
            $score += 16;
        }
        if ($isDellTemp) {
            $score += 14;
        }
        if ($isDellStatus && !$isDellId) {
            $score += 8;
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
            // Live temperature / humidity status (env managers) — not config thresh
            if (preg_match('/(status|current).*(temp|temperature)|(temp|temperature).*(status|current)|probetemperature|probestatustemp/', $s)
                && !preg_match('/config|thresh/', $s)
            ) {
                $score += 28;
            }
            if (preg_match('/(status|current).*(humid|humidity)|(humid|humidity).*(status|current)|probehumidity|probestatushumid/', $s)
                && !preg_match('/config|thresh/', $s)
            ) {
                $score += 28;
            }
            if (preg_match('/\btemp|temperature\b/', $s) && !preg_match('/config|thresh/', $s)) {
                $score += 8;
            }
            // Environmental manager / probes (AP9340 EMS / IEM / uio) — live only
            if (preg_match('/emsprobestatus|iemstatus|emstatus|uio.*status|sensorstatus|probe.*status/', $s)
                && !preg_match('/config|thresh/', $s)
            ) {
                $score += 18;
            }
            if (preg_match('/1\.3\.6\.1\.4\.1\.318\.1\.1\.10\.(2|3)\.(5|13|2)/', $oid)
                || preg_match('/1\.3\.6\.1\.4\.1\.318\.1\.1\.10\.3\.13\./', $oid)
            ) {
                $score += 12; // EMS/IEM status table branches
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
            // Plausible °C on Liebert LGP present-value tables (often unnamed without MIBs)
            if (str_starts_with($oid, '1.3.6.1.4.1.476.1.42.3.9.20.')
                && $num >= -40 && $num <= 90
            ) {
                $score += 10;
            }
        }
        // Soft demote MIB-II sysOR / uptime clutter so cooling Discover is not mostly 1.2.1.1.9.*
        if (str_starts_with($oid, '1.3.6.1.2.1.1.9.') || str_starts_with($oid, '1.3.6.1.2.1.1.8.')) {
            $score -= 20;
        }

        // Liebert LGP product identity (Vertiv / IS-UNITY-ICOM2) — keep visible on cooling Discover
        if (str_starts_with($oid, '1.3.6.1.4.1.476.1.42.2.')) {
            $score += 30;
            return max(1, $score);
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
        // Skip on env/EMS trees so temperature readings never become "possible watts"
        // Skip Dell identity branch (service tag / express service code are not power)
        $isEnvTree = (bool)preg_match('/1\.3\.6\.1\.4\.1\.318\.1\.1\.(10|25)\./', $oid)
            || (bool)preg_match('/\btemp|temperature|humid|humidity|probe|ems|iem|environmental\b/', $s);
        $isDellIdentity = (bool)preg_match('/^1\.3\.6\.1\.4\.1\.674\.10892\.5\.1\.3\./', $oid);
        if ($isDellIdentity) {
            if (str_ends_with($oid, '.12.0') || str_contains($s, 'servicetag')) {
                $hints[] = 'Dell service tag';
            } elseif (str_ends_with($oid, '.6.0') || str_contains($s, 'model')) {
                $hints[] = 'Dell system model';
            } elseif (str_ends_with($oid, '.13.0') || str_contains($s, 'express')) {
                $hints[] = 'Dell express service code (identity, not watts)';
            } else {
                $hints[] = 'Dell identity';
            }
        } elseif (!$hints && !$nonMetric && $num !== null && !$isEnvTree) {
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
        // Env manager live temp/humidity per probe index
        foreach (self::proposeEnvMapKeys($candidates) as $k => $oid) {
            $map[$k] = $oid;
        }
        // Liebert / Vertiv air unit supply/return (LGP condition IDs)
        foreach (self::proposeCoolingMapKeys($candidates) as $k => $oid) {
            $map[$k] = $oid;
        }

        // OIDs already claimed as env metrics must never become watts/amps
        $envOids = [];
        foreach ($map as $k => $oid) {
            if (preg_match('/^(temperature|humidity|dew_?point|supply_temp|return_temp|cooling)\./i', (string)$k)) {
                $envOids[(string)$oid] = true;
            }
        }
        $isEnvOid = static function (string $oid, string $hay) use ($envOids): bool {
            if (isset($envOids[$oid])) {
                return true;
            }
            // APC EMS / IEM / UIO env trees — not power
            if (preg_match('/1\.3\.6\.1\.4\.1\.318\.1\.1\.(10|25)\./', $oid)) {
                return true;
            }
            if (preg_match('/\btemp|temperature|humid|humidity|dewpoint|probe|ems|iem|envmon|environmental\b/', $hay)
                && !preg_match('/\bwatt|activepower|realpower|devicepower|phasepower\b/', $hay)
            ) {
                return true;
            }
            return false;
        };

        $pick = static function (array $c) use (&$watts, &$wattsScore, &$amps, &$ampsX10, $isEnvOid): void {
            $oid = $c['oid'];
            $hint = strtolower($c['hint'] ?? '');
            $name = strtolower((string)($c['name'] ?? ''));
            $n = $c['numeric'];
            $hay = $name . ' ' . $hint;
            $sc = (int)($c['score'] ?? 0);

            if (preg_match('/config|thresh|threshold|reset|timestamp|powerfactor|powersupply|properties/', $hay)) {
                return;
            }
            if ($isEnvOid($oid, $hay)) {
                return;
            }

            // Prefer device/total power — skip pure phase-instance leaves when we already map phases
            $isPhaseLeaf = (bool)preg_match('/phasestatus|phase\d|\.phase/i', $hay)
                || (bool)preg_match('/\.[123]$/', $oid);

            // Require power-ish name/hint — never assign watts from bare numeric range alone
            $looksWatts = str_contains($hay, 'watt')
                || str_contains($hay, 'mib: watts')
                || str_contains($hay, 'identdevicepowerwatts')
                || (str_contains($hay, 'identdevicepower') && !str_contains($hay, 'factor') && !str_contains($hay, 'va'))
                || str_starts_with($oid, '1.3.6.1.4.1.99999.2.1')
                || (str_contains($hint, 'possible watts')
                    && $n !== null && $n >= 50 && $n <= 100000
                    && !str_contains($hint, 'temperature')
                    && !str_contains($hint, 'humidity')
                    && !str_contains($hint, 'environmental'));

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

        // Fallback watts only with explicit power signal — never steal temperature OIDs
        // (previously: first enterprise numeric ≥20 → often EMS temp.1).
        // Never map rPDULoadStatusLoad (tenths A) or UPS-only trees as device watts for PDUs.
        if ($watts === null) {
            foreach ($candidates as $c) {
                if ($c['numeric'] === null || $c['numeric'] < 0 || ($c['score'] ?? 0) < 8) {
                    continue;
                }
                if (!str_starts_with((string)$c['oid'], '1.3.6.1.4.1.')) {
                    continue;
                }
                if ($phaseKeys && preg_match('/\.[123]$/', (string)$c['oid'])) {
                    continue;
                }
                $hay = strtolower((string)($c['name'] ?? '') . ' ' . (string)($c['hint'] ?? ''));
                if ($isEnvOid((string)$c['oid'], $hay)) {
                    continue;
                }
                if (preg_match('/config|threshold|factor|supply|temp|humid|probe/', $hay)) {
                    continue;
                }
                $oid = ltrim((string)$c['oid'], '.');
                // Current / load-state leaves are NOT watts (common AP7862 Discover mistake)
                if (preg_match('/loadstatusload|statuscurrent|phasestatuscurrent|(?<![a-z])current(?![a-z])/', $hay)
                    || preg_match('/^1\.3\.6\.1\.4\.1\.318\.1\.1\.12\.2\.3\.1\.1\.2(?:\.|$)/', $oid)
                    || preg_match('/^1\.3\.6\.1\.4\.1\.318\.1\.1\.26\.6\.3\.1\.5(?:\.|$)/', $oid)
                ) {
                    continue;
                }
                // UPS PowerNet output tree — not rack PDU load
                if (preg_match('/^1\.3\.6\.1\.4\.1\.318\.1\.1\.1\./', $oid)) {
                    continue;
                }
                // Require power keyword or known *power* OID columns (not whole 12/26 trees)
                $powerish = (bool)preg_match(
                    '/watt|activepower|realpower|devicepower|phasepower|statuspower|identdevicepower|devicesstatuspower/',
                    $hay
                )
                    || preg_match('/^1\.3\.6\.1\.4\.1\.318\.1\.1\.26\.4\.3\.1\.5(?:\.|$)/', $oid) // rPDU2DeviceStatusPower
                    || preg_match('/^1\.3\.6\.1\.4\.1\.318\.1\.1\.12\.1\.16(?:\.|$)/', $oid) // rPDUIdentDevicePowerWatts
                    || str_starts_with($oid, '1.3.6.1.4.1.99999.2.1')
                    || (str_starts_with($oid, '1.3.6.1.4.1.3808.') && preg_match('/watt|power/', $hay));
                if (!$powerish) {
                    continue;
                }
                // Prefer rPDU2 status power over Ident (Ident often 0 on AP78xx)
                if (preg_match('/^1\.3\.6\.1\.4\.1\.318\.1\.1\.26\.4\.3\.1\.5(?:\.|$)/', $oid)
                    || preg_match('/devicestatuspower(?!factor|supply)/', $hay)
                ) {
                    $watts = $oid;
                    break;
                }
                if ($watts === null) {
                    $watts = $oid;
                }
            }
        }
        if ($ampsX10 !== null) {
            $map['amps_x10'] = $ampsX10;
        } elseif ($amps !== null) {
            $map['amps'] = $amps;
        }
        if ($watts !== null) {
            // APC rPDU2 device status power is hundredths of kW — encode scale in the key
            $wOid = ltrim($watts, '.');
            if (preg_match('/^1\.3\.6\.1\.4\.1\.318\.1\.1\.26\.4\.3\.1\.5(?:\.|$)/', $wOid)) {
                $map['watts_hundredths_kw'] = $watts;
            } else {
                $map['watts'] = $watts;
            }
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

            // Prefer rPDU2DeviceStatusPower (hundredths kW) over Ident watts (often 0 on AP78xx)
            if (preg_match('/rpdu2devicesstatuspower(?!factor|supply)|devicestatuspower(?!factor|supply)/', $hay)
                || preg_match('/^1\.3\.6\.1\.4\.1\.318\.1\.1\.26\.4\.3\.1\.5(?:\.|$)/', ltrim($oid, '.'))
            ) {
                $consider('watts_hundredths_kw', $c, 100);
            }
            if (preg_match('/identdevicepowerwatts|rpduidentdevicepowerwatts/', $hay)) {
                $consider('watts', $c, 40); // lower than rPDU2 — Ident often stuck at 0
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
     * Stock classic rPDU + rPDU2 load map for AP78xx/AP7862 when Discover is sparse.
     * Does not overwrite keys already proposed from live candidates.
     *
     * @param array<string,string> $proposed
     * @param array<string,mixed> $collected
     * @return array<string,string>
     */
    private static function injectApcClassicRpduMap(array $proposed, string $sysDescr, array $collected): array
    {
        $hay = strtolower($sysDescr);
        $looksRackPdu = (bool)preg_match(
            '/rpdu|rack.?pdu|switched.?rack|metered|\bpdu\b|'
            . '\bap7\d{3}\b|\bap8\d{3}\b|\bap9\d{3}\b|'
            . 'mn:\s*ap[789]\d{3}|hw02_rpdu|hw05_rpdu|aos_.*rpdu/i',
            $hay
        );
        if (!$looksRackPdu) {
            foreach ($collected as $oid => $_) {
                $o = (string)$oid;
                if (str_starts_with($o, '1.3.6.1.4.1.318.1.1.12.')
                    || str_starts_with($o, '1.3.6.1.4.1.318.1.1.26.')
                ) {
                    $looksRackPdu = true;
                    break;
                }
            }
        }
        if (!$looksRackPdu) {
            return $proposed;
        }

        // Did this agent answer any rPDU2 leaf? (AOS 3.9 AP7862 usually does not)
        $hasRpdu2 = false;
        foreach ($collected as $oid => $_) {
            if (str_starts_with((string)$oid, '1.3.6.1.4.1.318.1.1.26.')) {
                $hasRpdu2 = true;
                break;
            }
        }

        // Classic rPDU is authoritative on AP7862. rPDU2 keys only when the agent has them —
        // otherwise Poll wastes GETs on noSuchName and never improves load.
        $defaults = [
            'sysDescr' => '1.3.6.1.2.1.1.1.0',
            'sysUpTime' => '1.3.6.1.2.1.1.3.0',
            'serial_no' => '1.3.6.1.4.1.318.1.1.12.1.6.0',
            // Ident power (W) — 0 until L–L voltage is set on AP7xxx NMC
            'watts' => '1.3.6.1.4.1.318.1.1.12.1.16.0',
            'va' => '1.3.6.1.4.1.318.1.1.12.1.18.0',
            'pf_x1000' => '1.3.6.1.4.1.318.1.1.12.1.17.0',
            // Input / L–L voltage used for power calc on AP7xxx (volts AC)
            'input_volts' => '1.3.6.1.4.1.318.1.1.12.1.15.0',
            'phase_rated_amps' => '1.3.6.1.4.1.318.1.1.12.2.1.1.0',
            // Classic load status (tenths A) — primary load on AP7862 AOS 3.9
            'phase1_amps_x10' => '1.3.6.1.4.1.318.1.1.12.2.3.1.1.2.1',
            'phase2_amps_x10' => '1.3.6.1.4.1.318.1.1.12.2.3.1.1.2.2',
            'phase3_amps_x10' => '1.3.6.1.4.1.318.1.1.12.2.3.1.1.2.3',
            'phase1_load_state' => '1.3.6.1.4.1.318.1.1.12.2.3.1.1.3.1',
            'phase2_load_state' => '1.3.6.1.4.1.318.1.1.12.2.3.1.1.3.2',
            'phase3_load_state' => '1.3.6.1.4.1.318.1.1.12.2.3.1.1.3.3',
        ];
        if ($hasRpdu2) {
            $defaults['watts_hundredths_kw'] = '1.3.6.1.4.1.318.1.1.26.4.3.1.5.1';
            $defaults['phase1_volts'] = '1.3.6.1.4.1.318.1.1.26.6.3.1.6.1';
            $defaults['phase2_volts'] = '1.3.6.1.4.1.318.1.1.26.6.3.1.6.2';
            $defaults['phase3_volts'] = '1.3.6.1.4.1.318.1.1.26.6.3.1.6.3';
            $defaults['phase1_watts_hundredths_kw'] = '1.3.6.1.4.1.318.1.1.26.6.3.1.7.1';
            $defaults['phase2_watts_hundredths_kw'] = '1.3.6.1.4.1.318.1.1.26.6.3.1.7.2';
            $defaults['phase3_watts_hundredths_kw'] = '1.3.6.1.4.1.318.1.1.26.6.3.1.7.3';
        }

        foreach ($defaults as $key => $oid) {
            if (!isset($proposed[$key]) || $proposed[$key] === '' || $proposed[$key] === null) {
                $proposed[$key] = $oid;
            }
        }
        // Drop injected rPDU2 keys when agent never answered 26.x (stale overwrite maps)
        if (!$hasRpdu2) {
            foreach (array_keys($proposed) as $k) {
                $oid = is_string($proposed[$k] ?? null) ? ltrim((string)$proposed[$k], '.') : '';
                if ($oid !== '' && str_starts_with($oid, '1.3.6.1.4.1.318.1.1.26.')) {
                    // Keep only if user explicitly added and we saw no 26 — still drop on seed refresh
                    if (preg_match('/^(watts_hundredths_kw|phase[123]_(volts|watts))/', (string)$k)) {
                        unset($proposed[$k]);
                    }
                }
            }
            // Re-apply classic defaults for keys we removed
            foreach ($defaults as $key => $oid) {
                if (!isset($proposed[$key])) {
                    $proposed[$key] = $oid;
                }
            }
        }

        // Never leave plain "watts" pointing at load-status current (tenths A)
        if (isset($proposed['watts']) && is_string($proposed['watts'])) {
            $w = ltrim($proposed['watts'], '.');
            if (preg_match('/^1\.3\.6\.1\.4\.1\.318\.1\.1\.12\.2\.3\.1\.1\.2(?:\.|$)/', $w)) {
                $proposed['phase1_amps_x10'] = $proposed['watts'];
                $proposed['watts'] = '1.3.6.1.4.1.318.1.1.12.1.16.0';
            }
        }

        return $proposed;
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
        // EMS-only hosts (AP9340): do not probe rPDU2 outlet columns (each miss burns a timeout)
        if (preg_match('/ap9340|environmental.?manager|ems|iem|netbotz/i', $hay)
            && !preg_match('/rpdu|rack.?pdu|switched.?rack|metered.?rack/i', $hay)
        ) {
            return $proposed;
        }
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
     * Live env probe metrics for APC EMS / IEM / generic status temps.
     * Keys: temperature.1 … humidity.1 … (instance from OID suffix).
     *
     * @param list<array{oid:string,name?:?string,hint?:string,score?:int,numeric?:?float}> $candidates
     * @return array<string,string>
     */
    private static function proposeEnvMapKeys(array $candidates): array
    {
        $out = [];
        foreach ($candidates as $c) {
            $oid = (string)($c['oid'] ?? '');
            $name = strtolower((string)($c['name'] ?? ''));
            $hint = strtolower((string)($c['hint'] ?? ''));
            $hay = $name . ' ' . $hint . ' ' . $oid;
            if ($oid === '' || preg_match('/config|thresh|threshold|reset/', $hay)) {
                continue;
            }
            $inst = '1';
            if (preg_match('/\.(\d+)$/', $oid, $m)) {
                $inst = $m[1];
            }
            $isTemp = (bool)preg_match(
                '/(status|current).*(temp|temperature)|(temp|temperature).*(status|current)|probetemperature|statustemp/',
                $hay
            );
            $isHum = (bool)preg_match(
                '/(status|current).*(humid|humidity)|(humid|humidity).*(status|current)|probehumidity|statushumid/',
                $hay
            );
            // Known APC EMS status column bases (PowerNet emsProbeStatus*)
            if (preg_match('/1\.3\.6\.1\.4\.1\.318\.1\.1\.10\.3\.13\.1\.1\.3(?:\.|$)/', $oid)
                || preg_match('/1\.3\.6\.1\.4\.1\.318\.1\.1\.10\.2\.3\.2\.1\.4(?:\.|$)/', $oid)
            ) {
                $isTemp = true;
            }
            if (preg_match('/1\.3\.6\.1\.4\.1\.318\.1\.1\.10\.3\.13\.1\.1\.6(?:\.|$)/', $oid)
                || preg_match('/1\.3\.6\.1\.4\.1\.318\.1\.1\.10\.2\.3\.2\.1\.6(?:\.|$)/', $oid)
            ) {
                $isHum = true;
            }
            if ($isTemp) {
                $key = 'temperature.' . $inst;
                if (!isset($out[$key]) || ((int)($c['score'] ?? 0) > 20)) {
                    $out[$key] = $oid;
                }
            }
            if ($isHum) {
                $key = 'humidity.' . $inst;
                if (!isset($out[$key]) || ((int)($c['score'] ?? 0) > 20)) {
                    $out[$key] = $oid;
                }
            }
        }
        // Prefer base map keys without forcing every index when only .1 exists
        return $out;
    }

    /**
     * Liebert LGP condition present-values → cooling map keys.
     * Known community IDs: 5002 supply, 4291 return (iCOM / DS family).
     *
     * @param list<array{oid:string,name?:?string,hint?:string,score?:int,numeric?:?float}> $candidates
     * @return array<string,string>
     */
    private static function proposeCoolingMapKeys(array $candidates): array
    {
        $out = [];
        $known = [
            '5002' => 'supply_temp',
            '5001' => 'supply_temp',
            '5003' => 'supply_temp',
            '4291' => 'return_temp',
            '4240' => 'return_temp',
            '4241' => 'return_temp',
            '307' => 'humidity',
        ];
        // Identity leaves always proposable when present (even if filtered from candidates)
        $identityMap = [
            '1.3.6.1.4.1.476.1.42.2.1.1.0' => 'lgp_manufacturer',
            '1.3.6.1.4.1.476.1.42.2.1.2.0' => 'lgp_model',
            '1.3.6.1.4.1.476.1.42.2.1.3.0' => 'lgp_version',
            '1.3.6.1.4.1.476.1.42.2.1.5.0' => 'lgp_firmware',
        ];
        foreach ($candidates as $c) {
            $oid = (string)($c['oid'] ?? '');
            if ($oid === '' || !str_starts_with($oid, '1.3.6.1.4.1.476.')) {
                continue;
            }
            if (isset($identityMap[$oid]) && !isset($out[$identityMap[$oid]])) {
                $out[$identityMap[$oid]] = $oid;
            }
            $hay = strtolower((string)($c['name'] ?? '') . ' ' . (string)($c['hint'] ?? ''));
            $key = null;
            if (preg_match('/\.1\.20\.1\.2\.1\.(\d+)$/', $oid, $m) && isset($known[$m[1]])) {
                $key = $known[$m[1]];
            } elseif (preg_match('/supply|leaving|discharge/', $hay) && preg_match('/temp/', $hay)) {
                $key = 'supply_temp';
            } elseif (preg_match('/return|entering/', $hay) && preg_match('/temp/', $hay)) {
                $key = 'return_temp';
            } elseif (preg_match('/humid/', $hay)) {
                $key = 'humidity';
            }
            // Plausible temp °C without name
            if ($key === null) {
                $n = $c['numeric'] ?? null;
                if ($n !== null && $n >= -20 && $n <= 60 && preg_match('/\.1\.20\.1\.2\.1\.\d+$/', $oid)) {
                    $key = 'cooling.metric.' . preg_replace('/^.*\./', '', $oid);
                }
            }
            if ($key !== null && !isset($out[$key])) {
                $out[$key] = $oid;
            }
        }
        return $out;
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
     * True when manufacturer looks like Dell (for iDRAC field / host preference).
     */
    public static function isDellManufacturer(?string $manufacturer): bool
    {
        $m = strtolower(trim((string)$manufacturer));
        if ($m === '') {
            return false;
        }
        // "dell", "dell inc.", "dell technologies", "dell emc", ...
        return $m === 'dell'
            || str_starts_with($m, 'dell ')
            || str_starts_with($m, 'dell,')
            || str_starts_with($m, 'dell.');
    }

    /**
     * True when manufacturer looks like APC / Schneider PowerNet.
     */
    public static function isApcManufacturer(?string $manufacturer): bool
    {
        $m = strtolower(trim((string)$manufacturer));
        if ($m === '') {
            return false;
        }
        return (bool)preg_match(
            '/^(apc|american power conversion|schneider(\s+electric)?(\s+it)?|schneider-electric)\b/',
            $m
        ) || str_contains($m, 'american power');
    }

    /**
     * True when manufacturer looks like Liebert / Emerson / Vertiv cooling.
     */
    public static function isLiebertManufacturer(?string $manufacturer): bool
    {
        $m = strtolower(trim((string)$manufacturer));
        if ($m === '') {
            return false;
        }
        return (bool)preg_match('/\b(liebert|emerson|vertiv)\b/', $m);
    }

    /**
     * Pick Discover ruleset: apc | ups | liebert | idrac | default.
     * Priority: forced ruleset → family hint → inventory manufacturer → sysDescr → default.
     */
    public static function resolveRulesetId(
        string $forcedRuleset,
        string $familyHint,
        string $manufacturer,
        ?string $sysDescr
    ): string {
        $forced = strtolower(trim($forcedRuleset));
        if (in_array($forced, ['apc', 'ups', 'liebert', 'idrac', 'default'], true)) {
            return $forced;
        }

        $family = strtolower(trim($familyHint));
        if (in_array($family, ['cooling', 'liebert'], true)) {
            return 'liebert';
        }
        if (in_array($family, ['idrac', 'server_bmc'], true)) {
            return 'idrac';
        }
        if (in_array($family, ['ups'], true)) {
            return 'ups';
        }
        if (in_array($family, ['power', 'ems', 'apc'], true)) {
            // Power/EMS Discover stays on the tuned APC PowerNet path
            return 'apc';
        }
        if ($family === 'default') {
            return 'default';
        }

        // Inventory manufacturer (most reliable when set)
        if (self::isDellManufacturer($manufacturer)) {
            return 'idrac';
        }
        if (self::isLiebertManufacturer($manufacturer)) {
            return 'liebert';
        }
        // Model / name often includes Symmetra / UPS — prefer UPS ruleset over generic APC PDU walk
        $mfr = strtolower($manufacturer);
        if (preg_match('/\b(symmetra|smart-?ups|galaxy|easy.?ups)\b/', $mfr)
            || preg_match('/\bups\b/', $mfr)
        ) {
            return 'ups';
        }
        if (self::isApcManufacturer($manufacturer)) {
            return 'apc';
        }

        // sysDescr heuristics
        $sys = strtolower(trim((string)$sysDescr));
        if ($sys !== '') {
            if (preg_match(
                '/\b(idrac|integrated dell|dell inc|powered by dell|dellemc|openmanage)\b/',
                $sys
            )) {
                return 'idrac';
            }
            if (preg_match(
                '/\b(liebert|emerson|vertiv|icom|lgp|global products|crac|crah)\b/',
                $sys
            )) {
                return 'liebert';
            }
            if (preg_match(
                '/\b(symmetra|smart-?ups|galaxy|ups network management|powernet.*ups)\b/',
                $sys
            )) {
                return 'ups';
            }
            if (preg_match(
                '/\b(apc|powernet|schneider|rpdu|ap8\d{3}|ap9\d{3}|netbotz|american power)\b/',
                $sys
            )) {
                return 'apc';
            }
        }

        return 'default';
    }

    /**
     * @return list<string>
     */
    public static function walkRootsForRuleset(string $ruleset): array
    {
        return match ($ruleset) {
            'apc' => self::WALK_ROOTS_APC,
            'ups' => self::WALK_ROOTS_UPS,
            'liebert' => self::WALK_ROOTS_LIEBERT,
            'idrac' => self::WALK_ROOTS_IDRAC,
            default => self::WALK_ROOTS_DEFAULT,
        };
    }

    /**
     * SNMP target host for a device: Dell iDRAC when set, else mgmt_ip, else primary_ip.
     * @param array<string,mixed> $device
     */
    public static function snmpHostFromDevice(array $device): string
    {
        $idrac = trim((string)($device['idrac_host'] ?? ''));
        if ($idrac !== '' && self::isDellManufacturer($device['manufacturer'] ?? null)) {
            // Strip accidental scheme if pasted from a browser URL
            if (preg_match('#^https?://#i', $idrac)) {
                $idrac = (string)preg_replace('#^https?://#i', '', $idrac);
                $idrac = rtrim(explode('/', $idrac, 2)[0], '/');
            }
            return $idrac;
        }
        $host = trim((string)($device['mgmt_ip'] ?? ''));
        if ($host === '') {
            $host = trim((string)($device['primary_ip'] ?? ''));
        }
        return $host;
    }

    /**
     * Build https://… URL for the iDRAC web UI (opens in a new tab from the device page).
     */
    public static function idracWebUrl(?string $idracHost): ?string
    {
        $h = trim((string)$idracHost);
        if ($h === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $h)) {
            return $h;
        }
        // IPv6 without brackets → wrap for URL
        if (str_contains($h, ':') && !str_starts_with($h, '[')
            && filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
        ) {
            $h = '[' . $h . ']';
        }
        // Basic host safety (hostname, IPv4, bracketed IPv6, optional :port)
        if (!preg_match('#^(\[[0-9a-fA-F:]+\]|[a-zA-Z0-9][a-zA-Z0-9._-]*)(:\d{1,5})?$#', $h)
            && !filter_var($h, FILTER_VALIDATE_IP)
        ) {
            return null;
        }
        return 'https://' . $h . '/';
    }

    /**
     * Credentials array from a devices row.
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    public static function credsFromDevice(array $device): array
    {
        $host = self::snmpHostFromDevice($device);
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
            'context' => (string)($device['snmp_v3_context'] ?? ''),
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
                    if (trim((string)($prof['context_name'] ?? '')) !== '') {
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
     * Pre-flight: vendor (manufacturer), model, and IP must be set.
     * @param array<string,mixed> $device
     * @return array{ok:bool,vendor:string,model:string,host:string,missing:list<string>,uses_idrac:bool}
     */
    public static function discoverPrereqs(array $device): array
    {
        $vendor = trim((string)($device['manufacturer'] ?? ''));
        $model = trim((string)($device['model'] ?? ''));
        // Properties UI falls back to the device template — Discover must too
        if ($model === '') {
            $model = trim((string)($device['tpl_model'] ?? ''));
        }
        if ($vendor === '') {
            $vendor = trim((string)($device['tpl_manufacturer'] ?? ''));
        }
        if (($vendor === '' || $model === '') && !empty($device['template_id'])) {
            try {
                $tpl = Database::fetchOne(
                    'SELECT t.model, m.name AS manufacturer_name
                     FROM device_templates t
                     LEFT JOIN manufacturers m ON m.manufacturer_id = t.manufacturer_id
                     WHERE t.template_id = ?',
                    [(int)$device['template_id']]
                );
                if ($tpl) {
                    if ($model === '') {
                        $model = trim((string)($tpl['model'] ?? ''));
                    }
                    if ($vendor === '') {
                        $vendor = trim((string)($tpl['manufacturer_name'] ?? ''));
                    }
                }
            } catch (Throwable $e) {
                // ignore — report missing fields below
            }
        }
        $host = self::snmpHostFromDevice($device);
        $usesIdrac = $host !== ''
            && trim((string)($device['idrac_host'] ?? '')) !== ''
            && self::isDellManufacturer($vendor);
        $missing = [];
        if ($vendor === '') {
            $missing[] = 'manufacturer (vendor)';
        }
        if ($model === '') {
            $missing[] = 'model';
        }
        if ($host === '') {
            if (self::isDellManufacturer($vendor)) {
                $missing[] = 'iDRAC host, management IP, or primary IP';
            } else {
                $missing[] = 'management or primary IP';
            }
        }
        return [
            'ok' => $missing === [],
            'vendor' => $vendor,
            'model' => $model,
            'host' => $host,
            'missing' => $missing,
            'uses_idrac' => $usesIdrac,
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

    /**
     * Pre-flight for cooling units: manufacturer, model, primary IP.
     * @param array<string,mixed> $unit
     * @return array{ok:bool,vendor:string,model:string,host:string,missing:list<string>}
     */
    public static function discoverPrereqsCooling(array $unit): array
    {
        $vendor = trim((string)($unit['manufacturer'] ?? ''));
        $model = trim((string)($unit['model'] ?? ''));
        $host = trim((string)($unit['primary_ip'] ?? ''));
        if ($host === '') {
            $host = trim((string)($unit['hostname'] ?? ''));
        }
        $missing = [];
        if ($vendor === '') {
            $missing[] = 'manufacturer (vendor)';
        }
        if ($model === '') {
            $missing[] = 'model';
        }
        if ($host === '') {
            $missing[] = 'primary IP';
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
     * Credentials from a cooling_units row (PDU-style field names).
     * @param array<string,mixed> $unit
     * @return array<string,mixed>
     */
    public static function credsFromCooling(array $unit): array
    {
        $host = trim((string)($unit['primary_ip'] ?? ''));
        if ($host === '') {
            $host = trim((string)($unit['hostname'] ?? ''));
        }
        $version = strtolower((string)($unit['snmp_version'] ?? '3'));
        if ($version === 'v3') {
            $version = '3';
        }
        if ($version === '2' || $version === 'v2c') {
            $version = '2c';
        }
        if ($version === 'v1') {
            $version = '1';
        }
        if ($version === '') {
            $version = '3';
        }
        $community = (string)(Crypto::decryptQuiet($unit['snmp_community'] ?? null) ?? 'public');
        $secName = (string)($unit['snmp_security_name'] ?? '');
        if (($version === '1' || $version === '2c') && $secName === '') {
            $secName = $community !== '' ? $community : 'public';
        }
        $creds = [
            'host' => $host,
            'port' => (int)($unit['snmp_port'] ?? 161) ?: 161,
            'snmp_version' => $version,
            'security_name' => $secName,
            'security_level' => (string)($unit['snmp_v3_sec_level'] ?? ''),
            'auth_protocol' => (string)($unit['snmp_auth_protocol'] ?? 'SHA'),
            'auth_passphrase' => (string)(Crypto::decryptQuiet($unit['snmp_auth_passphrase'] ?? null) ?? ''),
            'priv_protocol' => (string)($unit['snmp_priv_protocol'] ?? 'AES'),
            'priv_passphrase' => (string)(Crypto::decryptQuiet($unit['snmp_priv_passphrase'] ?? null) ?? ''),
            'community' => $community !== '' ? $community : 'public',
            'context' => (string)($unit['snmp_context'] ?? ''),
        ];
        if (!empty($unit['snmp_v3_profile_id'])) {
            try {
                $prof = Database::fetchOne(
                    'SELECT * FROM snmp_v3_profiles WHERE profile_id = ? AND is_active = 1',
                    [(int)$unit['snmp_v3_profile_id']]
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
     * Assign site OID template to a cooling unit.
     */
    public static function assignTemplateToCooling(int $coolingUnitId, int $templateId): void
    {
        Database::update('cooling_units', [
            'snmp_site_template_id' => $templateId,
            'snmp_enabled' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'cooling_unit_id = :id', [':id' => $coolingUnitId]);
    }

    /**
     * Link a site OID template to a UPS unit for Poll now / scheduled poll.
     * Pass templateId 0 to clear the assignment (also turns off scheduled poll).
     */
    public static function assignTemplateToUps(int $upsId, int $templateId): void
    {
        if ($templateId > 0) {
            Database::update('ups_units', [
                'snmp_site_template_id' => $templateId,
                'snmp_enabled' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'ups_id = :id', [':id' => $upsId]);
            return;
        }
        Database::update('ups_units', [
            'snmp_site_template_id' => null,
            'snmp_auto_poll' => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'ups_id = :id', [':id' => $upsId]);
    }
}
