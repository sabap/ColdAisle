<?php
/**
 * OpenDCIM → ColdAisle import orchestrator.
 *
 * Mode A (default): merge into existing datacenter by name; never overwrite
 * cabinet floor-plan positions (pos_x/pos_y/rotation/front_facing) for existing rows.
 */
declare(strict_types=1);

class OpenDcimImportService
{
    public const SOURCE = 'opendcim';
    public const MODE_MERGE = 'A';
    public const MODE_NEW_DC = 'B';
    public const MODE_REBUILD_POS = 'C';

    private OpenDcimClient $client;
    /** @var array<string,mixed> */
    private array $options;
    /** @var list<string> */
    private array $log = [];
    /** @var array<string,int> */
    private array $stats = [];
    /** @var array<string,array<string,int>> entity => sourceId => localId */
    private array $memMap = [];
    private int $drySeq = 1000000;

    /** Cached API payloads */
    private ?array $cache = null;

    /**
     * @param array{
     *   mode?:string,
     *   dry_run?:bool,
     *   include_disposed?:bool,
     *   include_ports?:bool,
     *   include_power?:bool,
     *   include_audits?:bool,
     *   datacenter_ids?:list<int|string>|null,
     *   target_datacenter_id?:int|null,
     *   weight_unit?:string,
     *   progress?:callable|null
     * } $options
     */
    public function __construct(OpenDcimClient $client, array $options = [])
    {
        $this->client = $client;
        $this->options = array_merge([
            'mode' => self::MODE_MERGE,
            'dry_run' => true,
            'include_disposed' => false,
            'include_ports' => true,
            'include_power' => true,
            'include_audits' => true,
            'include_images' => true,
            'datacenter_ids' => null,
            'target_datacenter_id' => null,
            'weight_unit' => 'lb',
            'progress' => null,
        ], $options);
    }

    /** @return list<string> */
    public function logLines(): array
    {
        return $this->log;
    }

    /** @return array<string,int> */
    public function stats(): array
    {
        return $this->stats;
    }

    public function isDryRun(): bool
    {
        return !empty($this->options['dry_run']);
    }

    private function log(string $msg): void
    {
        $this->log[] = $msg;
        $cb = $this->options['progress'] ?? null;
        if (is_callable($cb)) {
            $cb($msg);
        }
    }

    private function bump(string $key, int $n = 1): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + $n;
    }

    private function emptyToNull(mixed $v): mixed
    {
        if ($v === null) {
            return null;
        }
        if (is_string($v) && trim($v) === '') {
            return null;
        }
        return $v;
    }

    private function emptyDate(?string $v): ?string
    {
        $v = $v !== null ? trim($v) : '';
        if ($v === '' || str_starts_with($v, '1970-01-01') || str_starts_with($v, '0000-')) {
            return null;
        }
        // date only
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $v, $m)) {
            return $m[1];
        }
        return null;
    }

    private function weightToKg(mixed $raw): ?float
    {
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }
        $n = (float)$raw;
        if ($n <= 0) {
            return null;
        }
        if (($this->options['weight_unit'] ?? 'lb') === 'lb') {
            return round($n * 0.45359237, 2);
        }
        return round($n, 2);
    }

    /**
     * Parse openDCIM cabinet Location like Z1-RA-R4 or SNHUC-Z1-RA-R1
     * (tree: ZONE 1 → ROW A → Z1-RA-R4).
     *
     * @return array{zone_num:?int,row_letter:?string,rack_num:?int}
     */
    public static function parseCabinetLocation(string $location): array
    {
        $out = ['zone_num' => null, 'row_letter' => null, 'rack_num' => null];
        // Z{zone}-R{rowLetter}-R{rack}  e.g. Z1-RA-R10 → zone 1, row A, rack 10
        if (preg_match('/Z(\d+)-R([A-Za-z]+)-R(\d+)/i', $location, $m)) {
            $out['zone_num'] = (int)$m[1];
            $token = strtoupper($m[2]); // "A" from "A" or "A" from "A" after -R([A-Z]+)-
            // Group is "A" when location is Z1-RA-R1 (-R + A + -R1)
            $out['row_letter'] = $token !== '' ? substr($token, -1) : null;
            $out['rack_num'] = (int)$m[3];
        }
        return $out;
    }

    /**
     * openDCIM "Primary IP / Hostname" → separate ColdAisle fields.
     *
     * @return array{primary_ip:?string,hostname:?string}
     */
    public static function splitPrimaryIpHostname(mixed $raw): array
    {
        $v = trim((string)($raw ?? ''));
        if ($v === '') {
            return ['primary_ip' => null, 'hostname' => null];
        }
        if (filter_var($v, FILTER_VALIDATE_IP)) {
            return ['primary_ip' => $v, 'hostname' => null];
        }
        // Hostnames / labels (not dotted-quad or IPv6)
        return ['primary_ip' => null, 'hostname' => $v];
    }

    private static function normalizeNameKey(string $name): string
    {
        return strtolower((string)preg_replace('/[\s\-_.]+/', '', $name));
    }

    /**
     * Infer manufacturer display name from template picture filenames (openDCIM API has no manufacturer list).
     *
     * @param list<array<string,mixed>> $templates
     */
    public static function inferManufacturerName(int $manufacturerId, array $templates): string
    {
        if ($manufacturerId <= 0) {
            return 'Unknown';
        }
        $votes = [];
        foreach ($templates as $t) {
            if ((int)($t['ManufacturerID'] ?? 0) !== $manufacturerId) {
                continue;
            }
            foreach (['FrontPictureFile', 'RearPictureFile'] as $f) {
                $fn = (string)($t[$f] ?? '');
                if (preg_match('/^([A-Za-z][A-Za-z0-9+]{1,24})[_-]/', $fn, $m)) {
                    $brand = self::normalizeBrandToken($m[1]);
                    if ($brand !== null) {
                        $votes[$brand] = ($votes[$brand] ?? 0) + 1;
                    }
                }
            }
        }
        if ($votes) {
            arsort($votes);
            return (string)array_key_first($votes);
        }
        return 'Manufacturer ' . $manufacturerId;
    }

    private static function normalizeBrandToken(string $token): ?string
    {
        $t = trim($token);
        if ($t === '' || is_numeric($t)) {
            return null;
        }
        // Skip obvious model/product fragments
        $skip = [
            'front', 'rear', 'panel', 'power', 'edge', 'hard', 'optiplex', 'fpr', 'nexus',
            'procurve', 'cloudkeyenterprise', 'unvrpro', 'unifi', 'pr1000rt2u',
            'signamaxpatchpanel24', 'signamaxpatchpanel48', 'dmpu4032', 'kmmled156',
            'poweredger650', 'cisco4300',
        ];
        if (in_array(strtolower($t), $skip, true)) {
            return null;
        }
        $aliases = [
            'cisco' => 'Cisco',
            'ciscosystems' => 'Cisco',
            'meraki' => 'Cisco',
            'dell' => 'Dell',
            'delemc' => 'Dell EMC',
            'dellemc' => 'Dell EMC',
            'emc' => 'Dell EMC',
            'hp' => 'HPE',
            'hpe' => 'HPE',
            'hewlettpackard' => 'HPE',
            'apc' => 'APC',
            'lenovo' => 'Lenovo',
            'ibm' => 'IBM',
            'fortinet' => 'Fortinet',
            'fortigate' => 'Fortinet',
            'ubiquiti' => 'Ubiquiti',
            'avaya' => 'Avaya',
            'ciena' => 'Ciena',
            'raritan' => 'Raritan',
            'nimble' => 'HPE',
            'mellanox' => 'NVIDIA',
            'exacqvision' => 'ExacqVision',
            'exaqvision' => 'ExacqVision',
            'geovision' => 'GeoVision',
            'linksys' => 'Linksys',
            'oracle' => 'Oracle',
            'citrix' => 'Citrix',
            'accedian' => 'Accedian',
            'generic' => 'Generic',
            'lg' => 'LG',
            'fatpipe' => 'FatPipe',
            'multitech' => 'MultiTech',
            'newmar' => 'Newmar',
            'caswell' => 'Caswell',
            'cardinalhealth' => 'Cardinal Health',
            'digi' => 'Digi',
            'hargray' => 'Hargray',
            'invidtech' => 'InVid Tech',
            'telco' => 'Telco',
            'telesyn' => 'Telesyn',
            'titanium' => 'Titanium',
            'ampnetconnect' => 'CommScope',
            'corningcopperpanel' => 'Corning',
        ];
        $key = strtolower($t);
        if (isset($aliases[$key])) {
            return $aliases[$key];
        }
        // Title-case unknown brands
        if (preg_match('/^[A-Za-z]+$/', $t) && strlen($t) >= 2 && strlen($t) <= 20) {
            return strtoupper($t[0]) . strtolower(substr($t, 1));
        }
        return null;
    }

    /**
     * Do not blank existing SNMP/IP when openDCIM has empty values.
     *
     * @param array<string,mixed> $fields
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function mergePreserveNonEmpty(array $fields, ?array $existing, array $keys): array
    {
        if (!$existing) {
            return $fields;
        }
        foreach ($keys as $k) {
            $new = $fields[$k] ?? null;
            if ($new === null || $new === '') {
                unset($fields[$k]);
            }
        }
        return $fields;
    }

    // ------------------------------------------------------------------
    // Identity map
    // ------------------------------------------------------------------

    public static function ensureMapTable(): void
    {
        Database::query(
            "IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'import_id_map')
             CREATE TABLE import_id_map (
                map_id INT IDENTITY(1,1) PRIMARY KEY,
                source NVARCHAR(40) NOT NULL,
                entity_type NVARCHAR(40) NOT NULL,
                source_id NVARCHAR(80) NOT NULL,
                local_id INT NOT NULL,
                updated_at DATETIME2 NOT NULL CONSTRAINT DF_import_id_map_upd DEFAULT SYSUTCDATETIME(),
                CONSTRAINT UQ_import_id_map UNIQUE (source, entity_type, source_id)
             )"
        );
    }

    public function resolveLocalId(string $entityType, string $sourceId): ?int
    {
        $sourceId = (string)$sourceId;
        if ($sourceId === '' || $sourceId === '0') {
            return null;
        }
        if (isset($this->memMap[$entityType][$sourceId])) {
            return $this->memMap[$entityType][$sourceId];
        }
        try {
            $id = Database::fetchValue(
                'SELECT local_id FROM import_id_map
                 WHERE source = ? AND entity_type = ? AND source_id = ?',
                [self::SOURCE, $entityType, $sourceId]
            );
            if ($id !== null && $id !== false) {
                $this->memMap[$entityType][$sourceId] = (int)$id;
                return (int)$id;
            }
        } catch (Throwable $e) {
            // table may not exist yet
        }
        return null;
    }

    public function rememberMapping(string $entityType, string $sourceId, int $localId): void
    {
        $sourceId = (string)$sourceId;
        $this->memMap[$entityType][$sourceId] = $localId;
        if ($this->isDryRun() || $localId >= 1000000) {
            return;
        }
        try {
            $existing = Database::fetchValue(
                'SELECT local_id FROM import_id_map
                 WHERE source = ? AND entity_type = ? AND source_id = ?',
                [self::SOURCE, $entityType, $sourceId]
            );
            if ($existing !== null && $existing !== false) {
                if ((int)$existing !== $localId) {
                    Database::update(
                        'import_id_map',
                        ['local_id' => $localId, 'updated_at' => date('Y-m-d H:i:s')],
                        'source = :s AND entity_type = :t AND source_id = :i',
                        [':s' => self::SOURCE, ':t' => $entityType, ':i' => $sourceId]
                    );
                }
                return;
            }
            Database::insert('import_id_map', [
                'source' => self::SOURCE,
                'entity_type' => $entityType,
                'source_id' => $sourceId,
                'local_id' => $localId,
            ]);
        } catch (Throwable $e) {
            try {
                Database::update(
                    'import_id_map',
                    ['local_id' => $localId, 'updated_at' => date('Y-m-d H:i:s')],
                    'source = :s AND entity_type = :t AND source_id = :i',
                    [':s' => self::SOURCE, ':t' => $entityType, ':i' => $sourceId]
                );
            } catch (Throwable $e2) {
                $this->log('WARN map ' . $entityType . '/' . $sourceId . ': ' . $e2->getMessage());
            }
        }
    }

    private function dryId(): int
    {
        return $this->drySeq++;
    }

    // ------------------------------------------------------------------
    // Type maps
    // ------------------------------------------------------------------

    public static function mapDeviceType(string $odType): string
    {
        $t = strtolower(trim($odType));
        return match ($t) {
            'server' => 'server',
            'switch', 'network switch' => 'network_switch',
            'storage array', 'storage' => 'storage_array',
            'storage switch' => 'storage_switch',
            'chassis' => 'chassis',
            'patch panel' => 'other',
            'appliance' => 'other',
            'physical infrastructure' => 'other',
            'cdu' => 'pdu',
            'router' => 'router',
            'firewall' => 'firewall',
            'ups' => 'ups',
            'kvm' => 'kvm',
            default => 'other',
        };
    }

    public static function mapStatus(string $odStatus): string
    {
        $s = strtolower(trim($odStatus));
        return match ($s) {
            'production' => 'production',
            'disposed' => 'disposed',
            'reserved' => 'reserved',
            'development' => 'development',
            'test', 'testing' => 'testing',
            'spare' => 'spare',
            'decommissioning' => 'decommissioning',
            default => 'production',
        };
    }

    // ------------------------------------------------------------------
    // Cache / summary / plan
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function loadCache(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $this->log('Fetching OpenDCIM inventory…');
        $this->cache = [
            'datacenter' => $this->client->collection('/api/v1/datacenter', 'datacenter'),
            'department' => $this->client->collection('/api/v1/department', 'department'),
            'cabinet' => $this->client->collection('/api/v1/cabinet', 'cabinet'),
            'device' => $this->client->collection('/api/v1/device', 'device'),
            'devicetemplate' => $this->client->collection('/api/v1/devicetemplate', 'devicetemplate'),
            'people' => $this->client->collection('/api/v1/people', 'people'),
        ];
        $this->log(sprintf(
            'Fetched: %d DC, %d cab, %d dev, %d tpl, %d dept, %d people',
            count($this->cache['datacenter']),
            count($this->cache['cabinet']),
            count($this->cache['device']),
            count($this->cache['devicetemplate']),
            count($this->cache['department']),
            count($this->cache['people'])
        ));
        return $this->cache;
    }

    /** @return array<string,mixed> */
    public function fetchSummary(): array
    {
        $test = $this->client->testConnection();
        $c = $this->loadCache();
        $byType = [];
        $byStatus = [];
        $cdu = 0;
        $inCab = 0;
        foreach ($c['device'] as $d) {
            $t = (string)($d['DeviceType'] ?? 'unknown');
            $s = (string)($d['Status'] ?? 'unknown');
            $byType[$t] = ($byType[$t] ?? 0) + 1;
            $byStatus[$s] = ($byStatus[$s] ?? 0) + 1;
            if (strcasecmp($t, 'CDU') === 0) {
                $cdu++;
            }
            if ((int)($d['Cabinet'] ?? 0) > 0) {
                $inCab++;
            }
        }
        arsort($byType);
        arsort($byStatus);
        $cabsByDc = [];
        $mapCoords = 0;
        foreach ($c['cabinet'] as $cab) {
            $dc = (string)($cab['DataCenterID'] ?? '0');
            $cabsByDc[$dc] = ($cabsByDc[$dc] ?? 0) + 1;
            if ((float)($cab['MapX1'] ?? 0) || (float)($cab['MapY1'] ?? 0)
                || (float)($cab['MapX2'] ?? 0) || (float)($cab['MapY2'] ?? 0)) {
                $mapCoords++;
            }
        }
        return [
            'connection' => $test,
            'datacenters' => array_map(static fn($dc) => [
                'id' => (int)($dc['DataCenterID'] ?? 0),
                'name' => (string)($dc['Name'] ?? ''),
                'sqft' => $dc['SquareFootage'] ?? null,
                'drawing' => $dc['DrawingFileName'] ?? null,
            ], $c['datacenter']),
            'cabinets' => [
                'total' => count($c['cabinet']),
                'by_datacenter' => $cabsByDc,
                'with_map_coords' => $mapCoords,
            ],
            'devices' => [
                'total' => count($c['device']),
                'in_cabinet' => $inCab,
                'cdu' => $cdu,
                'by_type' => $byType,
                'by_status' => $byStatus,
            ],
            'templates' => count($c['devicetemplate']),
            'departments' => count($c['department']),
            'people' => count($c['people']),
            'mode' => $this->options['mode'],
            'dry_run' => $this->isDryRun(),
        ];
    }

    /** @param array<string,mixed> $odDc */
    public function matchLocalDatacenter(array $odDc): ?array
    {
        $srcId = (string)($odDc['DataCenterID'] ?? '');
        if ($srcId !== '') {
            $mapped = $this->resolveLocalId('datacenter', $srcId);
            if ($mapped && $mapped < 1000000) {
                $row = Database::fetchOne('SELECT * FROM datacenters WHERE datacenter_id = ?', [$mapped]);
                if ($row) {
                    return $row;
                }
            }
        }
        $target = $this->options['target_datacenter_id'] ?? null;
        if ($target) {
            $row = Database::fetchOne('SELECT * FROM datacenters WHERE datacenter_id = ?', [(int)$target]);
            if ($row) {
                return $row;
            }
        }
        $name = trim((string)($odDc['Name'] ?? ''));
        if ($name === '') {
            return null;
        }
        return Database::fetchOne(
            'SELECT TOP 1 * FROM datacenters WHERE is_active = 1 AND name = ?',
            [$name]
        ) ?: null;
    }

    /** @return array<string,mixed> */
    public function planModeA(): array
    {
        $summary = $this->fetchSummary();
        $c = $this->loadCache();
        $dcs = $this->filterDatacenters($c['datacenter']);
        $plan = ['datacenters' => [], 'warnings' => [], 'mode' => self::MODE_MERGE];
        try {
            $plan['local_datacenters'] = Database::fetchAll(
                'SELECT datacenter_id, name FROM datacenters WHERE is_active = 1'
            );
        } catch (Throwable $e) {
            $plan['warnings'][] = 'Local DB: ' . $e->getMessage();
            $plan['summary'] = $summary;
            return $plan;
        }
        foreach ($dcs as $odDc) {
            $match = $this->matchLocalDatacenter($odDc);
            $entry = [
                'opendcim_id' => (int)($odDc['DataCenterID'] ?? 0),
                'opendcim_name' => (string)($odDc['Name'] ?? ''),
                'action' => $match ? 'merge' : 'skip',
                'local_datacenter_id' => $match ? (int)$match['datacenter_id'] : null,
                'local_name' => $match['name'] ?? null,
                'position_policy' => 'preserve_existing_cabinet_positions',
            ];
            if (!$match) {
                $entry['note'] = 'No matching local DC — use --target-dc=ID or rename local DC to match.';
                $plan['warnings'][] = 'Unmatched: ' . $entry['opendcim_name'];
            }
            $plan['datacenters'][] = $entry;
        }
        $plan['summary'] = $summary;
        return $plan;
    }

    /**
     * @param list<array<string,mixed>> $dcs
     * @return list<array<string,mixed>>
     */
    private function filterDatacenters(array $dcs): array
    {
        $filterIds = $this->options['datacenter_ids'];
        if (!is_array($filterIds) || !$filterIds) {
            return $dcs;
        }
        $want = array_map('strval', $filterIds);
        return array_values(array_filter($dcs, static function ($dc) use ($want) {
            return in_array((string)($dc['DataCenterID'] ?? ''), $want, true);
        }));
    }

    // ------------------------------------------------------------------
    // Mode A execute
    // ------------------------------------------------------------------

    /**
     * Run Mode A import (dry_run respected).
     *
     * @return array{stats:array<string,int>,log:list<string>,ok:bool,errors:list<string>}
     */
    public function runModeA(): array
    {
        $mode = strtoupper((string)($this->options['mode'] ?? self::MODE_MERGE));
        if ($mode !== self::MODE_MERGE) {
            throw new InvalidArgumentException('Only Mode A is implemented for writes. Use mode=A.');
        }

        $errors = [];
        $this->stats = [];
        $this->log = [];
        $this->log($this->isDryRun() ? '=== Mode A DRY-RUN ===' : '=== Mode A IMPORT ===');

        try {
            self::ensureMapTable();
        } catch (Throwable $e) {
            if (!$this->isDryRun()) {
                throw $e;
            }
            $this->log('WARN ensureMapTable: ' . $e->getMessage());
        }

        $c = $this->loadCache();
        $dcs = $this->filterDatacenters($c['datacenter']);

        // Global org data first
        try {
            $this->importDepartments($c['department']);
            $this->importPeople($c['people']);
            $this->importManufacturersAndTemplates($c['devicetemplate']);
            if (!empty($this->options['include_images'])) {
                $this->importTemplateImages($c['devicetemplate']);
            }
        } catch (Throwable $e) {
            $errors[] = 'Org/templates: ' . $e->getMessage();
            $this->log('ERROR ' . $e->getMessage());
        }

        foreach ($dcs as $odDc) {
            $odDcId = (string)($odDc['DataCenterID'] ?? '');
            $odName = (string)($odDc['Name'] ?? '');
            try {
                $localDc = $this->matchLocalDatacenter($odDc);
                if (!$localDc) {
                    $this->log("SKIP DC '{$odName}' (id={$odDcId}) — no local match (Mode A)");
                    $this->bump('datacenter_skipped');
                    continue;
                }
                $localDcId = (int)$localDc['datacenter_id'];
                $this->rememberMapping('datacenter', $odDcId, $localDcId);
                $this->log("MERGE DC '{$odName}' → local #{$localDcId}");
                $this->bump('datacenter_merged');

                $roomId = $this->ensureRoomForDc($localDcId, $odName);
                $this->importCabinetsForDc($odDcId, $localDcId, $roomId, $c['cabinet']);
                $this->importDevicesForDc($odDcId, $c['device'], $c['cabinet']);
                if (!empty($this->options['include_power'])) {
                    $this->importPdusAndPower($odDcId, $c['device'], $c['cabinet']);
                }
                // Parent device second pass (same DC devices)
                $this->linkParentDevices($odDcId, $c['device']);
                if (!empty($this->options['include_audits'])) {
                    $this->importCabinetAuditsForDc($odDcId, $c['cabinet']);
                }
            } catch (Throwable $e) {
                $errors[] = "DC {$odName}: " . $e->getMessage();
                $this->log('ERROR DC ' . $odName . ': ' . $e->getMessage());
            }
        }

        $this->log('=== Done ===');
        foreach ($this->stats as $k => $n) {
            $this->log(sprintf('  %-28s %d', $k, $n));
        }

        return [
            'ok' => $errors === [],
            'stats' => $this->stats,
            'log' => $this->log,
            'errors' => $errors,
            'dry_run' => $this->isDryRun(),
        ];
    }

    // ------------------------------------------------------------------
    // Departments / people / templates
    // ------------------------------------------------------------------

    /** @param list<array<string,mixed>> $rows */
    private function importDepartments(array $rows): void
    {
        $this->log('Importing departments…');
        foreach ($rows as $d) {
            $srcId = (string)($d['DeptID'] ?? '');
            $name = trim((string)($d['Name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $mapped = $this->resolveLocalId('department', $srcId);
            if ($mapped && $mapped < 1000000) {
                $this->bump('department_mapped');
                continue;
            }
            $existing = Database::fetchOne(
                'SELECT department_id FROM departments WHERE name = ?',
                [$name]
            );
            if ($existing) {
                $this->rememberMapping('department', $srcId, (int)$existing['department_id']);
                $this->bump('department_matched');
                continue;
            }
            $color = (string)($d['DeptColor'] ?? '');
            if ($color !== '' && $color[0] !== '#') {
                $color = '#' . ltrim($color, '#');
            }
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                $color = '#3b82f6';
            }
            if ($this->isDryRun()) {
                $id = $this->dryId();
                $this->rememberMapping('department', $srcId, $id);
                $this->bump('department_create');
                continue;
            }
            $id = Database::insert('departments', [
                'name' => $name,
                'manager_name' => $this->emptyToNull($d['ExecSponsor'] ?? null),
                'color_hex' => $color,
                'notes' => $this->emptyToNull($d['SDM'] ?? null),
                'is_active' => 1,
            ]);
            if (!$id) {
                $row = Database::fetchOne('SELECT department_id FROM departments WHERE name = ?', [$name]);
                $id = $row ? (int)$row['department_id'] : 0;
            }
            if ($id) {
                $this->rememberMapping('department', $srcId, $id);
                $this->bump('department_create');
            }
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function importPeople(array $rows): void
    {
        $this->log('Importing people → contacts…');
        foreach ($rows as $p) {
            $srcId = (string)($p['PersonID'] ?? '');
            if ($srcId === '' || $srcId === '0') {
                continue;
            }
            if (!empty($p['Disabled'])) {
                $this->bump('people_skipped_disabled');
                continue;
            }
            $mapped = $this->resolveLocalId('person', $srcId);
            if ($mapped && $mapped < 1000000) {
                $this->bump('people_mapped');
                continue;
            }
            $first = trim((string)($p['FirstName'] ?? ''));
            $last = trim((string)($p['LastName'] ?? ''));
            if ($first === '' && $last === '') {
                $uid = trim((string)($p['UserID'] ?? 'user'));
                $first = $uid;
                $last = '(import)';
            }
            if ($last === '') {
                $last = '—';
            }
            if ($first === '') {
                $first = '—';
            }
            $email = $this->emptyToNull($p['Email'] ?? null);
            $phone = $this->emptyToNull($p['Phone1'] ?? $p['Phone2'] ?? null);

            $existing = null;
            if ($email) {
                $existing = Database::fetchOne(
                    'SELECT contact_id FROM contacts WHERE email = ? AND is_active = 1',
                    [$email]
                );
            }
            if ($existing) {
                $this->rememberMapping('person', $srcId, (int)$existing['contact_id']);
                $this->bump('people_matched');
                continue;
            }
            if ($this->isDryRun()) {
                $this->rememberMapping('person', $srcId, $this->dryId());
                $this->bump('people_create');
                continue;
            }
            $id = Database::insert('contacts', [
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'phone' => $phone,
                'title' => null,
                'notes' => 'Imported from OpenDCIM UserID=' . ($p['UserID'] ?? ''),
                'is_active' => 1,
            ]);
            if ($id) {
                $this->rememberMapping('person', $srcId, $id);
                $this->bump('people_create');
            }
        }
    }

    /** @param list<array<string,mixed>> $templates */
    private function importManufacturersAndTemplates(array $templates): void
    {
        $this->log('Importing manufacturers + templates…');
        $mfrIds = [];
        foreach ($templates as $t) {
            $mid = (int)($t['ManufacturerID'] ?? 0);
            if ($mid > 0) {
                $mfrIds[$mid] = true;
            }
        }
        foreach (array_keys($mfrIds) as $mid) {
            $src = (string)$mid;
            $name = self::inferManufacturerName($mid, $templates);
            // Always prefer an existing brand row with the inferred name
            $byName = null;
            if (!preg_match('/^Manufacturer\s+\d+$/i', $name)) {
                $byName = Database::fetchOne(
                    'SELECT manufacturer_id, name FROM manufacturers WHERE name = ?',
                    [$name]
                );
            }
            if ($byName) {
                $this->rememberMapping('manufacturer', $src, (int)$byName['manufacturer_id']);
                $this->bump('manufacturer_matched');
                continue;
            }

            $mapped = $this->resolveLocalId('manufacturer', $src);
            if ($mapped && $mapped < 1000000) {
                // Fix placeholder names from earlier imports
                if (!$this->isDryRun()) {
                    $row = Database::fetchOne('SELECT name FROM manufacturers WHERE manufacturer_id = ?', [$mapped]);
                    $cur = (string)($row['name'] ?? '');
                    if ($cur === '' || preg_match('/^Manufacturer\s+\d+$/i', $cur)) {
                        if ($name !== $cur && !preg_match('/^Manufacturer\s+\d+$/i', $name)) {
                            Database::update('manufacturers', [
                                'name' => $name,
                                'notes' => 'OpenDCIM ManufacturerID=' . $mid,
                            ], 'manufacturer_id = :id', [':id' => $mapped]);
                            $this->bump('manufacturer_renamed');
                        }
                    }
                }
                $this->bump('manufacturer_mapped');
                continue;
            }
            $existing = Database::fetchOne('SELECT manufacturer_id, name FROM manufacturers WHERE name = ?', [$name]);
            if ($existing) {
                $this->rememberMapping('manufacturer', $src, (int)$existing['manufacturer_id']);
                $this->bump('manufacturer_matched');
                continue;
            }
            if ($this->isDryRun()) {
                $this->rememberMapping('manufacturer', $src, $this->dryId());
                $this->bump('manufacturer_create');
                continue;
            }
            $id = Database::insert('manufacturers', [
                'name' => $name,
                'notes' => 'OpenDCIM ManufacturerID=' . $mid,
            ]);
            if (!$id) {
                $row = Database::fetchOne('SELECT manufacturer_id FROM manufacturers WHERE name = ?', [$name]);
                $id = $row ? (int)$row['manufacturer_id'] : 0;
            }
            if ($id) {
                $this->rememberMapping('manufacturer', $src, $id);
                $this->bump('manufacturer_create');
            }
        }

        foreach ($templates as $t) {
            $srcId = (string)($t['TemplateID'] ?? '');
            if ($srcId === '' || $srcId === '0') {
                continue;
            }
            $model = trim((string)($t['Model'] ?? ''));
            if ($model === '') {
                continue;
            }
            $mapped = $this->resolveLocalId('template', $srcId);
            $mfrLocal = $this->resolveLocalId('manufacturer', (string)(int)($t['ManufacturerID'] ?? 0));
            $psCount = max(0, min(16, (int)($t['PSCount'] ?? 0)));
            $numPorts = max(0, (int)($t['NumPorts'] ?? 0));
            $psuDefs = [];
            for ($i = 0; $i < $psCount; $i++) {
                $psuDefs[] = [
                    'name' => $psCount === 1 ? 'PSU' : ('PSU-' . chr(65 + $i)),
                    'watts' => null,
                    'connector_type' => null,
                    'sort_order' => $i,
                    'notes' => null,
                ];
            }
            $fields = [
                'manufacturer_id' => $mfrLocal && $mfrLocal < 1000000 ? $mfrLocal : null,
                'model' => $model,
                'device_type' => self::mapDeviceType((string)($t['DeviceType'] ?? 'Server')),
                'u_height' => max(1, min(60, (int)($t['Height'] ?? 1))),
                'weight_kg' => $this->weightToKg($t['Weight'] ?? null),
                'watts' => isset($t['Wattage']) && is_numeric($t['Wattage']) && (float)$t['Wattage'] > 0
                    ? (float)$t['Wattage'] : null,
                'num_power_ports' => $psCount,
                'num_data_ports' => $numPorts,
                'power_supplies_json' => $psuDefs
                    ? json_encode($psuDefs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'snmp_template' => $this->emptyToNull($t['SNMPVersion'] ?? null),
                'notes' => $this->emptyToNull($t['Notes'] ?? null),
                'is_active' => 1,
            ];

            if ($mapped && $mapped < 1000000) {
                if (!$this->isDryRun()) {
                    Database::update('device_templates', $fields, 'template_id = :id', [':id' => $mapped]);
                }
                $this->bump('template_update');
                continue;
            }

            // Match by model + manufacturer
            $existing = null;
            if ($mfrLocal && $mfrLocal < 1000000) {
                $existing = Database::fetchOne(
                    'SELECT template_id FROM device_templates
                     WHERE is_active = 1 AND model = ? AND manufacturer_id = ?',
                    [$model, $mfrLocal]
                );
            }
            if (!$existing) {
                $existing = Database::fetchOne(
                    'SELECT template_id FROM device_templates WHERE is_active = 1 AND model = ?',
                    [$model]
                );
            }
            if ($existing) {
                $tid = (int)$existing['template_id'];
                $this->rememberMapping('template', $srcId, $tid);
                if (!$this->isDryRun()) {
                    Database::update('device_templates', $fields, 'template_id = :id', [':id' => $tid]);
                }
                $this->bump('template_matched');
                continue;
            }

            if ($this->isDryRun()) {
                $this->rememberMapping('template', $srcId, $this->dryId());
                $this->bump('template_create');
                continue;
            }
            $tid = Database::insert('device_templates', $fields);
            if (!$tid) {
                $row = Database::fetchOne(
                    'SELECT TOP 1 template_id FROM device_templates WHERE model = ? ORDER BY template_id DESC',
                    [$model]
                );
                $tid = $row ? (int)$row['template_id'] : 0;
            }
            if ($tid) {
                $this->rememberMapping('template', $srcId, $tid);
                $this->bump('template_create');
            }
        }
    }

    // ------------------------------------------------------------------
    // Space: room / zone / row / cabinet
    // ------------------------------------------------------------------

    private function ensureRoomForDc(int $localDcId, string $odDcName): int
    {
        $mapped = $this->resolveLocalId('room', 'dc:' . $localDcId);
        if ($mapped && $mapped < 1000000) {
            return $mapped;
        }
        $room = Database::fetchOne(
            'SELECT TOP 1 room_id FROM rooms WHERE datacenter_id = ? AND is_active = 1 ORDER BY room_id',
            [$localDcId]
        );
        if ($room) {
            $id = (int)$room['room_id'];
            $this->rememberMapping('room', 'dc:' . $localDcId, $id);
            $this->bump('room_matched');
            return $id;
        }
        if ($this->isDryRun()) {
            $id = $this->dryId();
            $this->rememberMapping('room', 'dc:' . $localDcId, $id);
            $this->bump('room_create');
            return $id;
        }
        $id = Database::insert('rooms', [
            'datacenter_id' => $localDcId,
            'name' => 'Main floor',
            'notes' => 'Auto-created for OpenDCIM import (' . $odDcName . ')',
            'is_active' => 1,
        ]);
        if (!$id) {
            $room = Database::fetchOne(
                'SELECT TOP 1 room_id FROM rooms WHERE datacenter_id = ? ORDER BY room_id DESC',
                [$localDcId]
            );
            $id = $room ? (int)$room['room_id'] : 0;
        }
        if ($id) {
            $this->rememberMapping('room', 'dc:' . $localDcId, $id);
            $this->bump('room_create');
        }
        return $id;
    }

    /**
     * Match/create power zone. openDCIM tree labels are "ZONE 1"; local may be "Zone-1" / "Zone 1".
     */
    private function ensureZone(int $localDcId, string $zoneSrcId, ?int $zoneNumFromLoc = null): ?int
    {
        if ($zoneSrcId === '' || $zoneSrcId === '0') {
            return null;
        }
        $num = $zoneNumFromLoc ?: (ctype_digit($zoneSrcId) ? (int)$zoneSrcId : null);
        $preferredLabel = $num !== null ? ('ZONE ' . $num) : ('Zone ' . $zoneSrcId);
        $aliases = [];
        if ($num !== null) {
            $aliases = [
                'ZONE ' . $num,
                'Zone ' . $num,
                'Zone-' . $num,
                'Zone' . $num,
                'Z' . $num,
                'Z-' . $num,
            ];
        }
        $aliases[] = 'Zone ' . $zoneSrcId;
        $aliases[] = 'ZONE ' . $zoneSrcId;
        $aliasKeys = array_unique(array_map([self::class, 'normalizeNameKey'], $aliases));

        try {
            $all = Database::fetchAll(
                'SELECT zone_id, name FROM power_zones WHERE datacenter_id = ?',
                [$localDcId]
            );
        } catch (Throwable $e) {
            $all = [];
        }
        // Prefer matching an existing site zone (even if import_id_map points at a duplicate)
        foreach ($all as $z) {
            $key = self::normalizeNameKey((string)$z['name']);
            if (in_array($key, $aliasKeys, true)) {
                $id = (int)$z['zone_id'];
                $this->rememberMapping('zone', $zoneSrcId, $id);
                $this->bump('zone_matched');
                return $id;
            }
        }
        $mapped = $this->resolveLocalId('zone', $zoneSrcId);
        if ($mapped && $mapped < 1000000) {
            return $mapped;
        }

        if ($this->isDryRun()) {
            $id = $this->dryId();
            $this->rememberMapping('zone', $zoneSrcId, $id);
            $this->bump('zone_create');
            return $id;
        }
        $id = Database::insert('power_zones', [
            'datacenter_id' => $localDcId,
            'name' => $preferredLabel,
            'description' => 'OpenDCIM ZoneID=' . $zoneSrcId,
            'feed_type' => 'A',
            'color_hex' => '#ef4444',
        ]);
        if (!$id) {
            $row = Database::fetchOne(
                'SELECT zone_id FROM power_zones WHERE datacenter_id = ? AND name = ?',
                [$localDcId, $preferredLabel]
            );
            $id = $row ? (int)$row['zone_id'] : 0;
        }
        if ($id) {
            $this->rememberMapping('zone', $zoneSrcId, $id);
            $this->bump('zone_create');
        }
        return $id ?: null;
    }

    /**
     * Match/create cabinet row. Tree labels "ROW A"; locations Z1-RA-R* → letter A.
     */
    private function ensureRow(
        int $roomId,
        int $localDcId,
        string $rowSrcId,
        ?int $zoneId,
        ?string $rowLetter,
        string $fallbackHint
    ): ?int {
        if ($rowSrcId === '' || $rowSrcId === '0') {
            return null;
        }
        $letter = $rowLetter !== null && $rowLetter !== '' ? strtoupper($rowLetter) : null;
        $preferredLabel = $letter !== null ? ('ROW ' . $letter) : (
            $fallbackHint !== '' ? $fallbackHint : ('Row ' . $rowSrcId)
        );
        $aliases = [];
        if ($letter !== null) {
            $aliases = [
                'ROW ' . $letter,
                'Row ' . $letter,
                'Row-' . $letter,
                'R' . $letter,
                'R ' . $letter,
                $letter,
            ];
            if ($fallbackHint !== '') {
                $aliases[] = $fallbackHint;
            }
        }
        if ($fallbackHint !== '') {
            $aliases[] = $fallbackHint;
        }
        $aliases[] = 'Row ' . $rowSrcId;
        $aliasKeys = array_unique(array_map([self::class, 'normalizeNameKey'], $aliases));

        try {
            $all = Database::fetchAll(
                'SELECT row_id, name, zone_id FROM cabinet_rows WHERE room_id = ?',
                [$roomId]
            );
        } catch (Throwable $e) {
            $all = [];
        }
        // Prefer site rows (Row A) over import duplicates (Z1-RA)
        foreach ($all as $r) {
            $key = self::normalizeNameKey((string)$r['name']);
            if (in_array($key, $aliasKeys, true)) {
                $id = (int)$r['row_id'];
                if (!$this->isDryRun() && $zoneId && $zoneId < 1000000 && empty($r['zone_id'])) {
                    Database::update('cabinet_rows', ['zone_id' => $zoneId], 'row_id = :id', [':id' => $id]);
                }
                $this->rememberMapping('row', $rowSrcId, $id);
                $this->bump('row_matched');
                return $id;
            }
        }
        $mapped = $this->resolveLocalId('row', $rowSrcId);
        if ($mapped && $mapped < 1000000) {
            return $mapped;
        }

        if ($this->isDryRun()) {
            $id = $this->dryId();
            $this->rememberMapping('row', $rowSrcId, $id);
            $this->bump('row_create');
            return $id;
        }
        $id = Database::insert('cabinet_rows', [
            'room_id' => $roomId,
            'name' => $preferredLabel,
            'data_center_id' => $localDcId,
            'zone_id' => $zoneId && $zoneId < 1000000 ? $zoneId : null,
        ]);
        if (!$id) {
            $row = Database::fetchOne(
                'SELECT row_id FROM cabinet_rows WHERE room_id = ? AND name = ?',
                [$roomId, $preferredLabel]
            );
            $id = $row ? (int)$row['row_id'] : 0;
        }
        if ($id) {
            $this->rememberMapping('row', $rowSrcId, $id);
            $this->bump('row_create');
        }
        return $id ?: null;
    }

    /**
     * @param list<array<string,mixed>> $allCabs
     */
    private function importCabinetsForDc(string $odDcId, int $localDcId, int $roomId, array $allCabs): void
    {
        $cabs = array_values(array_filter($allCabs, static function ($c) use ($odDcId) {
            return (string)($c['DataCenterID'] ?? '') === $odDcId;
        }));
        $this->log('Importing ' . count($cabs) . ' cabinets for OpenDCIM DC ' . $odDcId . '…');
        $newIndex = 0;
        foreach ($cabs as $cab) {
            $srcId = (string)($cab['CabinetID'] ?? '');
            if ($srcId === '' || $srcId === '0') {
                continue;
            }
            $name = trim((string)($cab['Location'] ?? $cab['ShowCabinetLabel'] ?? ''));
            if ($name === '') {
                $name = 'Cabinet ' . $srcId;
            }
            // Tree: ZONE 1 → ROW A → Z1-RA-R1 (Location encodes zone + row)
            $parsed = self::parseCabinetLocation($name);
            $zoneId = $this->ensureZone(
                $localDcId,
                (string)($cab['ZoneID'] ?? ''),
                $parsed['zone_num']
            );
            $rowHint = '';
            if (preg_match('/^(.*)-R\d+$/i', $name, $m)) {
                $rowHint = $m[1]; // Z1-RA
            }
            $rowId = $this->ensureRow(
                $roomId,
                $localDcId,
                (string)($cab['CabRowID'] ?? ''),
                $zoneId,
                $parsed['row_letter'],
                $rowHint
            );

            $uHeight = max(1, min(60, (int)($cab['CabinetHeight'] ?? 42)));
            $maxKw = isset($cab['MaxKW']) && is_numeric($cab['MaxKW']) && (float)$cab['MaxKW'] > 0
                ? (float)$cab['MaxKW'] : null;
            $maxW = $this->weightToKg($cab['MaxWeight'] ?? null);
            $install = $this->emptyDate(isset($cab['InstallationDate']) ? (string)$cab['InstallationDate'] : null);
            $notes = $this->emptyToNull($cab['Notes'] ?? null);

            $fieldsNoPos = [
                'room_id' => $roomId < 1000000 ? $roomId : $roomId, // dry-run room may be synthetic — skip write
                'row_id' => $rowId && $rowId < 1000000 ? $rowId : null,
                'name' => $name,
                'u_height' => $uHeight,
                'max_kw' => $maxKw,
                'max_weight_kg' => $maxW,
                'installation_date' => $install,
                'notes' => $notes,
                'is_active' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            $mapped = $this->resolveLocalId('cabinet', $srcId);
            if ($mapped && $mapped < 1000000) {
                if (!$this->isDryRun()) {
                    // Mode A: never write positions
                    unset($fieldsNoPos['room_id']); // keep existing room unless null? keep room stable
                    Database::update('cabinets', [
                        'row_id' => $fieldsNoPos['row_id'],
                        'name' => $fieldsNoPos['name'],
                        'u_height' => $fieldsNoPos['u_height'],
                        'max_kw' => $fieldsNoPos['max_kw'],
                        'max_weight_kg' => $fieldsNoPos['max_weight_kg'],
                        'installation_date' => $fieldsNoPos['installation_date'],
                        'notes' => $fieldsNoPos['notes'],
                        'is_active' => 1,
                        'updated_at' => $fieldsNoPos['updated_at'],
                    ], 'cabinet_id = :id', [':id' => $mapped]);
                }
                $this->bump('cabinet_update');
                continue;
            }

            // Match by name within this DC's rooms
            $existing = null;
            if (!$this->isDryRun() || $roomId < 1000000) {
                try {
                    $existing = Database::fetchOne(
                        'SELECT c.cabinet_id FROM cabinets c
                         INNER JOIN rooms r ON r.room_id = c.room_id
                         WHERE r.datacenter_id = ? AND c.name = ? AND c.is_active = 1',
                        [$localDcId, $name]
                    );
                } catch (Throwable $e) {
                    $existing = null;
                }
            }
            if ($existing) {
                $cid = (int)$existing['cabinet_id'];
                $this->rememberMapping('cabinet', $srcId, $cid);
                if (!$this->isDryRun()) {
                    Database::update('cabinets', [
                        'row_id' => $fieldsNoPos['row_id'],
                        'u_height' => $fieldsNoPos['u_height'],
                        'max_kw' => $fieldsNoPos['max_kw'],
                        'max_weight_kg' => $fieldsNoPos['max_weight_kg'],
                        'installation_date' => $fieldsNoPos['installation_date'],
                        'notes' => $fieldsNoPos['notes'],
                        'updated_at' => $fieldsNoPos['updated_at'],
                    ], 'cabinet_id = :id', [':id' => $cid]);
                }
                $this->bump('cabinet_matched');
                continue;
            }

            // New cabinet — place on a simple grid (do not use OpenDCIM pixel maps in Mode A)
            $gx = ($newIndex % 10) * 1.0;
            $gy = intdiv($newIndex, 10) * 1.2;
            $newIndex++;

            if ($this->isDryRun() || $roomId >= 1000000) {
                $this->rememberMapping('cabinet', $srcId, $this->dryId());
                $this->bump('cabinet_create');
                continue;
            }
            $cid = Database::insert('cabinets', [
                'room_id' => $roomId,
                'row_id' => $fieldsNoPos['row_id'],
                'name' => $name,
                'u_height' => $uHeight,
                'width_mm' => 600,
                'depth_mm' => 1200,
                'max_kw' => $maxKw,
                'max_weight_kg' => $maxW,
                'pos_x' => $gx,
                'pos_y' => $gy,
                'pos_z' => 0,
                'rotation_deg' => 0,
                'front_facing' => 'north',
                'installation_date' => $install,
                'notes' => $notes,
                'is_active' => 1,
            ]);
            if (!$cid) {
                $row = Database::fetchOne(
                    'SELECT TOP 1 cabinet_id FROM cabinets WHERE room_id = ? AND name = ? ORDER BY cabinet_id DESC',
                    [$roomId, $name]
                );
                $cid = $row ? (int)$row['cabinet_id'] : 0;
            }
            if ($cid) {
                $this->rememberMapping('cabinet', $srcId, $cid);
                $this->bump('cabinet_create');
            }
        }
    }

    // ------------------------------------------------------------------
    // Devices
    // ------------------------------------------------------------------

    /**
     * @param list<array<string,mixed>> $allDevices
     * @param list<array<string,mixed>> $allCabs
     */
    private function importDevicesForDc(string $odDcId, array $allDevices, array $allCabs): void
    {
        $cabIds = [];
        foreach ($allCabs as $cab) {
            if ((string)($cab['DataCenterID'] ?? '') === $odDcId) {
                $cabIds[(string)$cab['CabinetID']] = true;
            }
        }
        $devices = array_values(array_filter($allDevices, static function ($d) use ($cabIds) {
            $cab = (string)($d['Cabinet'] ?? '0');
            // include unassigned devices only if Cabinet 0 and we cannot scope — skip orphans for Mode A per DC
            return $cab !== '' && $cab !== '0' && isset($cabIds[$cab]);
        }));
        $this->log('Importing ' . count($devices) . ' devices for OpenDCIM DC ' . $odDcId . '…');

        foreach ($devices as $d) {
            $srcId = (string)($d['DeviceID'] ?? '');
            if ($srcId === '' || $srcId === '0') {
                continue;
            }
            $type = (string)($d['DeviceType'] ?? '');
            if (strcasecmp($type, 'CDU') === 0) {
                // handled in power phase
                $this->bump('device_skipped_cdu');
                continue;
            }
            $status = self::mapStatus((string)($d['Status'] ?? 'Production'));
            if ($status === 'disposed' && empty($this->options['include_disposed'])) {
                $this->bump('device_skipped_disposed');
                continue;
            }

            $label = trim((string)($d['Label'] ?? ''));
            if ($label === '') {
                $label = 'Device ' . $srcId;
            }
            $localCab = $this->resolveLocalId('cabinet', (string)($d['Cabinet'] ?? ''));
            if (!$localCab || $localCab >= 1000000) {
                // cabinet not imported (dry synthetic still OK for dry-run stats)
                if (!$this->isDryRun()) {
                    $this->bump('device_skipped_no_cabinet');
                    continue;
                }
            }

            $tplLocal = $this->resolveLocalId('template', (string)(int)($d['TemplateID'] ?? 0));
            $deptLocal = $this->resolveLocalId('department', (string)(int)($d['Owner'] ?? 0));
            $contactLocal = $this->resolveLocalId('person', (string)(int)($d['PrimaryContact'] ?? 0));

            $psCount = max(0, min(16, (int)($d['PowerSupplyCount'] ?? 0)));
            // openDCIM "Primary IP / Hostname" is a single field — never put hostnames in primary_ip
            $ipHost = self::splitPrimaryIpHostname($d['PrimaryIP'] ?? null);
            $fields = [
                'cabinet_id' => ($localCab && $localCab < 1000000) ? $localCab : null,
                'template_id' => ($tplLocal && $tplLocal < 1000000) ? $tplLocal : null,
                'department_id' => ($deptLocal && $deptLocal < 1000000) ? $deptLocal : null,
                'owner_contact_id' => ($contactLocal && $contactLocal < 1000000) ? $contactLocal : null,
                'label' => $label,
                'serial_no' => $this->emptyToNull($d['SerialNo'] ?? null),
                'asset_tag' => $this->emptyToNull($d['AssetTag'] ?? null),
                'device_type' => self::mapDeviceType($type),
                'position_u' => isset($d['Position']) && (int)$d['Position'] > 0 ? (int)$d['Position'] : null,
                'u_height' => max(1, min(60, (int)($d['Height'] ?? 1))),
                'half_depth' => !empty($d['HalfDepth']) ? 1 : 0,
                'back_side' => !empty($d['BackSide']) ? 1 : 0,
                'nominal_watts' => isset($d['NominalWatts']) && is_numeric($d['NominalWatts']) && (float)$d['NominalWatts'] > 0
                    ? (float)$d['NominalWatts'] : null,
                'weight_kg' => $this->weightToKg($d['Weight'] ?? null),
                'num_data_ports' => max(0, (int)($d['Ports'] ?? 0)),
                'status' => $status,
                'install_date' => $this->emptyDate(isset($d['InstallDate']) ? (string)$d['InstallDate'] : null),
                'manufacture_date' => $this->emptyDate(isset($d['MfgDate']) ? (string)$d['MfgDate'] : null),
                'warranty_provider' => $this->emptyToNull($d['WarrantyCo'] ?? null),
                'warranty_end' => $this->emptyDate(isset($d['WarrantyExpire']) ? (string)$d['WarrantyExpire'] : null),
                'notes' => $this->emptyToNull($d['Notes'] ?? null),
                'is_active' => $status === 'disposed' ? 0 : 1,
            ];
            // Only set the side of IP/hostname that openDCIM actually provided (don't null the other)
            if ($ipHost['primary_ip']) {
                $fields['primary_ip'] = $ipHost['primary_ip'];
            }
            if ($ipHost['hostname']) {
                $fields['hostname'] = $ipHost['hostname'];
                // Clear mistaken prior import of hostname into primary_ip
                if (!$ipHost['primary_ip']) {
                    $fields['primary_ip'] = null;
                }
            }
            // SNMP only if useful
            $snmpVer = $this->emptyToNull($d['SNMPVersion'] ?? null);
            $snmpComm = $this->emptyToNull($d['SNMPCommunity'] ?? null);
            if ($snmpVer && $snmpComm) {
                $fields['snmp_version'] = $snmpVer;
                $fields['snmp_community'] = $snmpComm;
            }

            $mapped = $this->resolveLocalId('device', $srcId);
            $deviceId = null;
            if ($mapped && $mapped < 1000000) {
                $deviceId = $mapped;
                if (!$this->isDryRun()) {
                    // If hostname-like value was stored in primary_ip, move it
                    if ($ipHost['hostname'] && !$ipHost['primary_ip']) {
                        $fields['primary_ip'] = null;
                    }
                    Database::update('devices', $fields, 'device_id = :id', [':id' => $deviceId]);
                }
                $this->bump('device_update');
            } else {
                // match by label + cabinet
                $existing = null;
                if ($fields['cabinet_id']) {
                    $existing = Database::fetchOne(
                        'SELECT device_id FROM devices WHERE cabinet_id = ? AND label = ? AND is_active = 1',
                        [$fields['cabinet_id'], $label]
                    );
                }
                if ($existing) {
                    $deviceId = (int)$existing['device_id'];
                    $this->rememberMapping('device', $srcId, $deviceId);
                    if (!$this->isDryRun()) {
                        Database::update('devices', $fields, 'device_id = :id', [':id' => $deviceId]);
                    }
                    $this->bump('device_matched');
                } elseif ($this->isDryRun() || !$fields['cabinet_id']) {
                    $deviceId = $this->dryId();
                    $this->rememberMapping('device', $srcId, $deviceId);
                    $this->bump('device_create');
                } else {
                    $deviceId = Database::insert('devices', $fields);
                    if (!$deviceId) {
                        $row = Database::fetchOne(
                            'SELECT TOP 1 device_id FROM devices WHERE label = ? ORDER BY device_id DESC',
                            [$label]
                        );
                        $deviceId = $row ? (int)$row['device_id'] : 0;
                    }
                    if ($deviceId) {
                        $this->rememberMapping('device', $srcId, $deviceId);
                        $this->bump('device_create');
                    }
                }
            }

            if (!$deviceId) {
                continue;
            }

            // PSUs for real devices only
            if (!$this->isDryRun() && $deviceId < 1000000 && $psCount > 0) {
                $this->ensureDevicePsus($deviceId, $psCount);
            }

            // Data ports
            if (!empty($this->options['include_ports']) && !$this->isDryRun() && $deviceId < 1000000) {
                try {
                    $this->importDataPorts($srcId, $deviceId);
                } catch (Throwable $e) {
                    $this->log('WARN ports device ' . $srcId . ': ' . $e->getMessage());
                    $this->bump('deviceport_error');
                }
            }
        }
    }

    private function ensureDevicePsus(int $deviceId, int $psCount): void
    {
        $existing = Database::fetchAll(
            'SELECT power_supply_id, name, sort_order FROM device_power_supplies WHERE device_id = ?',
            [$deviceId]
        );
        if (count($existing) >= $psCount) {
            $this->bump('psu_existing');
            return;
        }
        $have = count($existing);
        for ($i = $have; $i < $psCount; $i++) {
            $name = $psCount === 1 ? 'PSU' : ('PSU-' . chr(65 + $i));
            Database::insert('device_power_supplies', [
                'device_id' => $deviceId,
                'name' => $name,
                'watts' => null,
                'connector_type' => null,
                'sort_order' => $i,
            ]);
            $this->bump('psu_create');
        }
    }

    private function importDataPorts(string $odDeviceId, int $localDeviceId): void
    {
        $ports = $this->client->collection('/api/v1/deviceport/' . rawurlencode($odDeviceId), 'deviceport');
        if (!$ports) {
            return;
        }
        $existing = Database::fetchAll(
            "SELECT port_id, port_number FROM device_ports WHERE device_id = ? AND port_type = 'data'",
            [$localDeviceId]
        );
        $byNum = [];
        foreach ($existing as $p) {
            $byNum[(int)$p['port_number']] = (int)$p['port_id'];
        }
        foreach ($ports as $p) {
            $num = (int)($p['PortNumber'] ?? 0);
            if ($num <= 0) {
                continue;
            }
            $label = $this->emptyToNull($p['Label'] ?? null) ?: ('Port ' . $num);
            $notes = $this->emptyToNull($p['Notes'] ?? null);
            if (isset($byNum[$num])) {
                Database::update('device_ports', [
                    'label' => $label,
                    'notes' => $notes,
                ], 'port_id = :id', [':id' => $byNum[$num]]);
                $this->bump('deviceport_update');
            } else {
                Database::insert('device_ports', [
                    'device_id' => $localDeviceId,
                    'port_type' => 'data',
                    'port_number' => $num,
                    'label' => $label,
                    'media_type' => 'RJ45',
                    'notes' => $notes,
                ]);
                $this->bump('deviceport_create');
            }
        }
        Database::update(
            'devices',
            ['num_data_ports' => count($ports)],
            'device_id = :id',
            [':id' => $localDeviceId]
        );
    }

    /**
     * @param list<array<string,mixed>> $allDevices
     */
    private function linkParentDevices(string $odDcId, array $allDevices): void
    {
        foreach ($allDevices as $d) {
            $srcId = (string)($d['DeviceID'] ?? '');
            $parentSrc = (int)($d['ParentDevice'] ?? 0);
            if ($parentSrc <= 0 || $srcId === '') {
                continue;
            }
            $local = $this->resolveLocalId('device', $srcId);
            $parent = $this->resolveLocalId('device', (string)$parentSrc);
            if (!$local || !$parent || $local >= 1000000 || $parent >= 1000000) {
                continue;
            }
            if ($this->isDryRun()) {
                $this->bump('parent_link');
                continue;
            }
            Database::update(
                'devices',
                ['parent_device_id' => $parent],
                'device_id = :id',
                [':id' => $local]
            );
            $this->bump('parent_link');
        }
    }

    // ------------------------------------------------------------------
    // PDUs (CDU) + powerport mapping
    // ------------------------------------------------------------------

    /**
     * @param list<array<string,mixed>> $allDevices
     * @param list<array<string,mixed>> $allCabs
     */
    private function importPdusAndPower(string $odDcId, array $allDevices, array $allCabs): void
    {
        if (!function_exists('power_sync_outlet_inventory')) {
            require_once dirname(__DIR__, 2) . '/includes/power_helpers.php';
        }

        $cabIds = [];
        foreach ($allCabs as $cab) {
            if ((string)($cab['DataCenterID'] ?? '') === $odDcId) {
                $cabIds[(string)$cab['CabinetID']] = true;
            }
        }

        $cdus = array_values(array_filter($allDevices, static function ($d) use ($cabIds) {
            if (strcasecmp((string)($d['DeviceType'] ?? ''), 'CDU') !== 0) {
                return false;
            }
            $cab = (string)($d['Cabinet'] ?? '0');
            return isset($cabIds[$cab]);
        }));
        $this->log('Importing ' . count($cdus) . ' CDUs as PDUs…');

        foreach ($cdus as $d) {
            $srcId = (string)($d['DeviceID'] ?? '');
            $label = trim((string)($d['Label'] ?? ('CDU-' . $srcId)));
            $localCab = $this->resolveLocalId('cabinet', (string)($d['Cabinet'] ?? ''));
            if (!$localCab || ($localCab >= 1000000 && !$this->isDryRun())) {
                $this->bump('pdu_skipped_no_cabinet');
                continue;
            }

            // Fetch power ports for outlet count/labels
            $powerPorts = [];
            try {
                $powerPorts = $this->client->collection(
                    '/api/v1/powerport/' . rawurlencode($srcId),
                    'powerport'
                );
            } catch (Throwable $e) {
                $this->log('WARN powerport CDU ' . $srcId . ': ' . $e->getMessage());
            }
            $numOutlets = count($powerPorts);
            if ($numOutlets < 1) {
                $numOutlets = max(1, (int)($d['PowerSupplyCount'] ?? 24));
            }

            $mapped = $this->resolveLocalId('pdu', $srcId);
            $odIpHost = self::splitPrimaryIpHostname($d['PrimaryIP'] ?? null);
            $pduFields = [
                'cabinet_id' => $localCab < 1000000 ? $localCab : null,
                'name' => $label,
                'pdu_scope' => 'rack',
                'mount_style' => 'vertical_rear',
                'serial_no' => $this->emptyToNull($d['SerialNo'] ?? null),
                'num_outlets' => $numOutlets,
                'output_mode' => 'outlets',
                'notes' => 'OpenDCIM CDU DeviceID=' . $srcId,
                'is_active' => 1,
            ];
            // Only set IP when openDCIM has a real IP — never wipe an existing ColdAisle PDU IP
            if ($odIpHost['primary_ip']) {
                $pduFields['ip_address'] = $odIpHost['primary_ip'];
            }
            $snmpComm = $this->emptyToNull($d['SNMPCommunity'] ?? null);
            $snmpVer = $this->emptyToNull($d['SNMPVersion'] ?? null);
            if ($snmpComm && $snmpVer) {
                $pduFields['snmp_enabled'] = 1;
                $pduFields['snmp_version'] = $snmpVer;
                $pduFields['snmp_community'] = $snmpComm;
            }

            $pduId = null;
            if ($mapped && $mapped < 1000000) {
                $pduId = $mapped;
                if (!$this->isDryRun()) {
                    $exist = Database::fetchOne('SELECT * FROM pdus WHERE pdu_id = ?', [$pduId]);
                    $upd = $this->mergePreserveNonEmpty($pduFields, $exist, [
                        'ip_address', 'serial_no', 'snmp_community', 'snmp_version',
                    ]);
                    Database::update('pdus', $upd, 'pdu_id = :id', [':id' => $pduId]);
                }
                $this->bump('pdu_update');
            } else {
                $existing = null;
                if ($pduFields['cabinet_id']) {
                    $existing = Database::fetchOne(
                        'SELECT * FROM pdus WHERE cabinet_id = ? AND name = ? AND is_active = 1',
                        [$pduFields['cabinet_id'], $label]
                    );
                }
                // Also match pre-existing rack PDUs by name only (any cabinet) carefully
                if (!$existing) {
                    $existing = Database::fetchOne(
                        'SELECT * FROM pdus WHERE name = ? AND is_active = 1',
                        [$label]
                    );
                }
                if ($existing) {
                    $pduId = (int)$existing['pdu_id'];
                    $this->rememberMapping('pdu', $srcId, $pduId);
                    if (!$this->isDryRun()) {
                        $upd = $this->mergePreserveNonEmpty($pduFields, $existing, [
                            'ip_address', 'serial_no', 'snmp_community', 'snmp_version',
                        ]);
                        // Keep existing cabinet if OD cabinet missing
                        if (empty($upd['cabinet_id']) && !empty($existing['cabinet_id'])) {
                            unset($upd['cabinet_id']);
                        }
                        Database::update('pdus', $upd, 'pdu_id = :id', [':id' => $pduId]);
                    }
                    $this->bump('pdu_matched');
                } elseif ($this->isDryRun() || !$pduFields['cabinet_id']) {
                    $pduId = $this->dryId();
                    $this->rememberMapping('pdu', $srcId, $pduId);
                    $this->bump('pdu_create');
                } else {
                    $pduId = Database::insert('pdus', $pduFields);
                    if (!$pduId) {
                        $row = Database::fetchOne(
                            'SELECT TOP 1 pdu_id FROM pdus WHERE name = ? ORDER BY pdu_id DESC',
                            [$label]
                        );
                        $pduId = $row ? (int)$row['pdu_id'] : 0;
                    }
                    if ($pduId) {
                        $this->rememberMapping('pdu', $srcId, $pduId);
                        $this->bump('pdu_create');
                    }
                }
            }

            if (!$pduId || $this->isDryRun() || $pduId >= 1000000) {
                continue;
            }

            // Power links resolve CDU DeviceID via import map entity_type=pdu
            power_sync_outlet_inventory($pduId, $numOutlets, 'C13', null);
            // Apply labels from power ports
            $outlets = Database::fetchAll(
                'SELECT outlet_id, outlet_number FROM pdu_outlets WHERE pdu_id = ?',
                [$pduId]
            );
            $byNum = [];
            foreach ($outlets as $o) {
                $byNum[(int)$o['outlet_number']] = (int)$o['outlet_id'];
            }
            foreach ($powerPorts as $pp) {
                $num = (int)($pp['PortNumber'] ?? 0);
                if ($num <= 0 || !isset($byNum[$num])) {
                    continue;
                }
                $lab = $this->emptyToNull($pp['Label'] ?? null);
                if ($lab) {
                    Database::update(
                        'pdu_outlets',
                        ['label' => $lab],
                        'outlet_id = :id',
                        [':id' => $byNum[$num]]
                    );
                }
            }
            $this->bump('pdu_outlets_synced');
        }

        // Map device powerports → PDU outlets
        $this->log('Mapping device power connections…');
        $nonCdu = array_values(array_filter($allDevices, static function ($d) use ($cabIds) {
            if (strcasecmp((string)($d['DeviceType'] ?? ''), 'CDU') === 0) {
                return false;
            }
            $cab = (string)($d['Cabinet'] ?? '0');
            return isset($cabIds[$cab]);
        }));

        foreach ($nonCdu as $d) {
            $srcId = (string)($d['DeviceID'] ?? '');
            $status = self::mapStatus((string)($d['Status'] ?? ''));
            if ($status === 'disposed' && empty($this->options['include_disposed'])) {
                continue;
            }
            $localDev = $this->resolveLocalId('device', $srcId);
            if (!$localDev || $localDev >= 1000000) {
                continue;
            }
            try {
                $pps = $this->client->collection(
                    '/api/v1/powerport/' . rawurlencode($srcId),
                    'powerport'
                );
            } catch (Throwable $e) {
                continue;
            }
            if (!$pps) {
                continue;
            }
            $psus = Database::fetchAll(
                'SELECT * FROM device_power_supplies WHERE device_id = ? ORDER BY sort_order, power_supply_id',
                [$localDev]
            );
            // Ensure enough PSUs
            $need = count($pps);
            if (count($psus) < $need) {
                $this->ensureDevicePsus($localDev, $need);
                $psus = Database::fetchAll(
                    'SELECT * FROM device_power_supplies WHERE device_id = ? ORDER BY sort_order, power_supply_id',
                    [$localDev]
                );
            }

            foreach ($pps as $idx => $pp) {
                $cduSrc = (string)(int)($pp['ConnectedDeviceID'] ?? 0);
                $outletNum = (int)($pp['ConnectedPort'] ?? 0);
                if ($cduSrc === '0' || $outletNum <= 0) {
                    continue;
                }
                $pduId = $this->resolveLocalId('pdu', $cduSrc);
                if (!$pduId || $pduId >= 1000000) {
                    $this->bump('power_map_skip_no_pdu');
                    continue;
                }
                $psu = $psus[$idx] ?? $psus[0] ?? null;
                // Prefer PSU matching PortNumber
                $portNum = (int)($pp['PortNumber'] ?? ($idx + 1));
                foreach ($psus as $candidate) {
                    if ((int)$candidate['sort_order'] === $portNum - 1
                        || str_ends_with((string)$candidate['name'], (string)$portNum)) {
                        $psu = $candidate;
                        break;
                    }
                }
                if (!$psu) {
                    continue;
                }
                $outlet = Database::fetchOne(
                    'SELECT outlet_id FROM pdu_outlets WHERE pdu_id = ? AND outlet_number = ?',
                    [$pduId, $outletNum]
                );
                if (!$outlet) {
                    $this->bump('power_map_skip_no_outlet');
                    continue;
                }
                $oid = (int)$outlet['outlet_id'];
                $psuId = (int)$psu['power_supply_id'];
                if ($this->isDryRun()) {
                    $this->bump('power_map');
                    continue;
                }
                // Clear previous outlet if PSU moves
                if (!empty($psu['pdu_outlet_id']) && (int)$psu['pdu_outlet_id'] !== $oid) {
                    Database::update('pdu_outlets', [
                        'connected_device_id' => null,
                        'device_power_supply_id' => null,
                    ], 'outlet_id = :id', [':id' => (int)$psu['pdu_outlet_id']]);
                }
                Database::update('device_power_supplies', [
                    'pdu_id' => $pduId,
                    'pdu_outlet_id' => $oid,
                    'name' => $this->emptyToNull($pp['Label'] ?? null) ?: $psu['name'],
                ], 'power_supply_id = :id', [':id' => $psuId]);
                Database::update('pdu_outlets', [
                    'connected_device_id' => $localDev,
                    'device_power_supply_id' => $psuId,
                ], 'outlet_id = :id', [':id' => $oid]);
                $this->bump('power_map');
            }
        }
    }

    // ------------------------------------------------------------------
    // Cabinet audits
    // ------------------------------------------------------------------

    /**
     * Import openDCIM CertifyAudit / CabinetAudit rows into cabinet_audits.
     *
     * @param list<array<string,mixed>> $allCabs
     */
    private function importCabinetAuditsForDc(string $odDcId, array $allCabs): void
    {
        $cabs = array_values(array_filter($allCabs, static function ($c) use ($odDcId) {
            return (string)($c['DataCenterID'] ?? '') === $odDcId;
        }));
        $this->log('Importing cabinet audits for ' . count($cabs) . ' cabinets…');

        foreach ($cabs as $cab) {
            $odCabId = (string)($cab['CabinetID'] ?? '');
            $localCab = $this->resolveLocalId('cabinet', $odCabId);
            if (!$localCab || $localCab >= 1000000) {
                continue;
            }

            $rows = [];
            try {
                // Prefer CabinetID filter; some installs return related rows for DeviceID too
                $json = $this->client->get('/api/v1/audit', ['CabinetID' => $odCabId]);
                $inner = $json['audit'] ?? [];
                if (is_array($inner)) {
                    $rows = array_values($inner);
                }
            } catch (Throwable $e) {
                $this->log('WARN audit cabinet ' . $odCabId . ': ' . $e->getMessage());
                $this->bump('audit_fetch_error');
                continue;
            }

            foreach ($rows as $a) {
                $class = (string)($a['Class'] ?? '');
                $action = (string)($a['Action'] ?? '');
                // Physical cabinet walkthroughs
                $isCabinetAudit = stripos($class, 'Cabinet') !== false
                    || strcasecmp($action, 'CertifyAudit') === 0;
                if (!$isCabinetAudit) {
                    $this->bump('audit_skipped_other');
                    continue;
                }

                $objId = (string)($a['ObjectID'] ?? '');
                // ObjectID should be the cabinet for CabinetAudit
                if ($objId !== '' && $objId !== '0' && $objId !== $odCabId) {
                    // Row might be for another object in the same response
                    $altCab = $this->resolveLocalId('cabinet', $objId);
                    if ($altCab && $altCab < 1000000) {
                        $localCab = $altCab;
                    }
                }

                $when = $this->emptyToNull($a['Time'] ?? null);
                $user = trim((string)($a['UserID'] ?? ''));
                $prop = (string)($a['Property'] ?? '');
                $newVal = trim((string)($a['NewVal'] ?? ''));
                $oldVal = trim((string)($a['OldVal'] ?? ''));
                $comments = null;
                if ($newVal !== '') {
                    $comments = $prop !== '' && strcasecmp($prop, 'Comments') !== 0
                        ? ($prop . ': ' . $newVal)
                        : $newVal;
                } elseif ($oldVal !== '') {
                    $comments = $prop !== '' ? ($prop . ': ' . $oldVal) : $oldVal;
                }
                if ($comments === null || $comments === '') {
                    $comments = trim($action . ' ' . $class);
                }

                $auditedAt = $when;
                // Normalize "2025-12-16 18:20:09" for SQL Server
                if ($auditedAt && preg_match('/^\d{4}-\d{2}-\d{2}/', $auditedAt)) {
                    $auditedAt = str_replace('T', ' ', substr($auditedAt, 0, 19));
                } else {
                    $auditedAt = date('Y-m-d H:i:s');
                }

                // Dedup: same cabinet + time (and similar comment when present)
                try {
                    $exists = Database::fetchOne(
                        'SELECT cabinet_audit_id FROM cabinet_audits
                         WHERE cabinet_id = ? AND audited_at = ?',
                        [$localCab, $auditedAt]
                    );
                } catch (Throwable $e) {
                    $exists = null;
                }
                if ($exists) {
                    $this->bump('audit_matched');
                    continue;
                }

                if ($this->isDryRun()) {
                    $this->bump('audit_create');
                    continue;
                }

                try {
                    Database::insert('cabinet_audits', [
                        'cabinet_id' => $localCab,
                        'audited_by' => null,
                        'audited_by_name' => $user !== '' ? $user : 'openDCIM',
                        'certified' => 1,
                        'comments' => $comments,
                        'audited_at' => $auditedAt,
                    ]);
                    $this->bump('audit_create');
                } catch (Throwable $e) {
                    $this->log('WARN audit insert cab ' . $localCab . ': ' . $e->getMessage());
                    $this->bump('audit_error');
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Template images
    // ------------------------------------------------------------------

    /**
     * Download openDCIM template front/rear pictures into storage/uploads/templates/{id}/.
     *
     * @param list<array<string,mixed>> $templates
     */
    private function importTemplateImages(array $templates): void
    {
        try {
            if (!class_exists('ImageUpload')) {
                require_once dirname(__DIR__) . '/Services/ImageUpload.php';
            }
        } catch (Throwable $e) {
            $this->log('WARN ImageUpload not available: ' . $e->getMessage());
            return;
        }

        if ($this->client->isOfflineCache()) {
            $this->log('Template images: offline mode — skipped (need live openDCIM /pictures/)');
            $this->bump('image_skipped_offline');
            return;
        }

        $this->log('Importing template images…');
        $withPics = 0;
        foreach ($templates as $t) {
            $front = trim((string)($t['FrontPictureFile'] ?? ''));
            $rear = trim((string)($t['RearPictureFile'] ?? ''));
            if ($front !== '' || $rear !== '') {
                $withPics++;
            }
        }
        $this->log("  templates with pictures: $withPics");

        $n = 0;
        foreach ($templates as $t) {
            $n++;
            try {
                $srcId = (string)($t['TemplateID'] ?? '');
                $localId = $this->resolveLocalId('template', $srcId);
                if (!$localId || $localId >= 1000000) {
                    continue;
                }

                $uHeight = max(1, (int)($t['Height'] ?? 1));
                $front = trim((string)($t['FrontPictureFile'] ?? ''));
                $rear = trim((string)($t['RearPictureFile'] ?? ''));
                if ($front === '' && $rear === '') {
                    continue;
                }

                if ($this->isDryRun()) {
                    if ($front !== '') {
                        $this->bump('image_front_would_import');
                    }
                    if ($rear !== '') {
                        $this->bump('image_rear_would_import');
                    }
                    continue;
                }

                $existing = Database::fetchOne(
                    'SELECT front_picture, rear_picture FROM device_templates WHERE template_id = ?',
                    [$localId]
                );
                $updates = [];

                if ($front !== '' && empty($existing['front_picture'])) {
                    $rel = $this->fetchAndStoreTemplateImage($localId, $front, 'front', $uHeight);
                    if ($rel) {
                        $updates['front_picture'] = $rel;
                        $this->bump('image_front_import');
                    } else {
                        $this->bump('image_front_fail');
                    }
                } elseif ($front !== '' && !empty($existing['front_picture'])) {
                    $this->bump('image_front_exists');
                }

                if ($rear !== '' && empty($existing['rear_picture'])) {
                    $rel = $this->fetchAndStoreTemplateImage($localId, $rear, 'rear', $uHeight);
                    if ($rel) {
                        $updates['rear_picture'] = $rel;
                        $this->bump('image_rear_import');
                    } else {
                        $this->bump('image_rear_fail');
                    }
                } elseif ($rear !== '' && !empty($existing['rear_picture'])) {
                    $this->bump('image_rear_exists');
                }

                if ($updates) {
                    Database::update('device_templates', $updates, 'template_id = :id', [':id' => $localId]);
                }

                if ($n % 25 === 0) {
                    $this->log("  image progress: $n / " . count($templates));
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }
            } catch (Throwable $e) {
                $this->log('WARN template image: ' . $e->getMessage());
                $this->bump('image_error');
            }
        }
    }

    private function fetchAndStoreTemplateImage(
        int $templateId,
        string $filename,
        string $stem,
        int $uHeight
    ): ?string {
        $filename = basename(str_replace(['\\', '..'], ['/', ''], $filename));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return null;
        }

        // Prefer unencoded path first (openDCIM serves /pictures/Name.png)
        $bytes = $this->client->downloadBinary('/pictures/' . $filename);
        if ($bytes === null && preg_match('/[^A-Za-z0-9._-]/', $filename)) {
            $bytes = $this->client->downloadBinary('/pictures/' . rawurlencode($filename));
        }
        if ($bytes === null) {
            $bytes = $this->client->downloadBinary('/drawings/' . $filename);
        }
        if ($bytes === null || strlen($bytes) < 32) {
            return null;
        }

        $tmpDir = App::ROOT . '/storage/tmp/opendcim_images';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: 'png');
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
            $ext = 'png';
        }
        $tmp = $tmpDir . '/' . $templateId . '_' . $stem . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (@file_put_contents($tmp, $bytes) === false) {
            return null;
        }

        $destDir = App::ROOT . '/storage/uploads/templates/' . $templateId;
        $dest = $destDir . '/' . $stem . '.jpg';
        try {
            $result = ImageUpload::processFromPath($tmp, $dest, $uHeight);
            @unlink($tmp);
            $rel = 'templates/' . $templateId . '/' . basename($result['path']);
            return $rel;
        } catch (Throwable $e) {
            // Fallback: store original bytes
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0775, true);
            }
            $fallback = $destDir . '/' . $stem . '.' . $ext;
            @rename($tmp, $fallback);
            if (is_file($fallback)) {
                return 'templates/' . $templateId . '/' . basename($fallback);
            }
            $this->log('WARN image ' . $filename . ': ' . $e->getMessage());
            @unlink($tmp);
            return null;
        }
    }
}
