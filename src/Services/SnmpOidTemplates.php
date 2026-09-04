<?php
/**
 * Curated SNMP OID template catalog for poll targets.
 * Data: config/snmp_oid_templates.json (optional override).
 */
declare(strict_types=1);

class SnmpOidTemplates
{
    private static ?array $catalog = null;

    public static function catalogPath(): string
    {
        return App::ROOT . '/config/snmp_oid_templates.json';
    }

    /** @return array{version?:int,description?:string,templates:list<array>} */
    public static function load(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }
        $path = self::catalogPath();
        if (!is_file($path)) {
            self::$catalog = ['version' => 0, 'templates' => self::builtinFallback()];
            return self::$catalog;
        }
        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || empty($data['templates']) || !is_array($data['templates'])) {
            self::$catalog = ['version' => 0, 'templates' => self::builtinFallback()];
            return self::$catalog;
        }
        self::$catalog = $data;
        return self::$catalog;
    }

    /**
     * @return list<array{
     *   id:string,vendor:string,label:string,notes:string,family:string,seed:bool,
     *   model:string,oid_map:array<string,string>,metric_count:int
     * }>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::load()['templates'] as $t) {
            if (!is_array($t) || empty($t['id'])) {
                continue;
            }
            $map = is_array($t['oid_map'] ?? null) ? $t['oid_map'] : [];
            $metricCount = 0;
            foreach ($map as $k => $v) {
                if (!is_string($k) || str_starts_with($k, '_') || trim((string)$v) === '') {
                    continue;
                }
                $metricCount++;
            }
            $out[] = [
                'id' => (string)$t['id'],
                'vendor' => (string)($t['vendor'] ?? ''),
                'label' => (string)($t['label'] ?? $t['id']),
                'notes' => (string)($t['notes'] ?? ''),
                'family' => (string)($t['family'] ?? self::inferFamily((string)$t['id'], (string)($t['label'] ?? ''))),
                'seed' => !empty($t['seed']) || str_contains(strtolower((string)($t['notes'] ?? '')), 'seed')
                    || str_contains(strtolower((string)($t['notes'] ?? '')), 'verify'),
                'model' => (string)($t['model'] ?? self::modelFromLabel((string)($t['label'] ?? $t['id']))),
                'oid_map' => $map,
                'metric_count' => $metricCount,
            ];
        }
        return $out;
    }

    /** Packs suitable for one-click site template install (skip blank custom). */
    public static function installablePacks(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn(array $t) => $t['id'] !== 'custom' && $t['metric_count'] > 0
        ));
    }

    private static function inferFamily(string $id, string $label): string
    {
        $s = strtolower($id . ' ' . $label);
        if (str_contains($s, 'ups')) {
            return 'ups';
        }
        if (str_contains($s, 'cool') || str_contains($s, 'crac') || str_contains($s, 'liebert')
            || str_contains($s, 'thermal') || str_contains($s, 'env')
        ) {
            return 'cooling';
        }
        if (str_contains($s, 'pdu') || str_contains($s, 'xpdu') || str_contains($s, 'rpdu')) {
            return 'pdu';
        }
        return 'meta';
    }

    private static function modelFromLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return 'Generic';
        }
        // Prefer text in parentheses
        if (preg_match('/\(([^)]+)\)/', $label, $m)) {
            return trim($m[1]);
        }
        return $label;
    }

    /**
     * Create or overwrite a site OID template from a built-in pack.
     *
     * @return array{created?:bool,overwritten?:bool,exists?:bool,template_id:int,name:string,pack_id:string,oid_map:array}
     */
    public static function installPackToSite(string $packId, bool $overwrite = false): array
    {
        $pack = self::get($packId);
        if (!$pack || $packId === 'custom') {
            throw new RuntimeException('Unknown or non-installable pack: ' . $packId);
        }
        if (!class_exists('SnmpDiscover')) {
            require_once __DIR__ . '/SnmpDiscover.php';
        }
        $vendor = trim((string)($pack['vendor'] ?? 'Catalog')) ?: 'Catalog';
        $model = trim((string)($pack['model'] ?? '')) ?: self::modelFromLabel((string)$pack['label']);
        $notes = 'Installed from built-in pack "' . $packId . '". ' . (string)($pack['notes'] ?? '');
        $result = SnmpDiscover::saveSiteTemplate(
            $vendor,
            $model,
            $pack['oid_map'],
            $overwrite,
            'catalog',
            substr($notes, 0, 500)
        );
        $result['pack_id'] = $packId;
        return $result;
    }

    /**
     * Convert a Vertiv NMS device template JSON (AC_Vertiv_Thermal style) into an oid_map.
     * Skips writable / unit-control points.
     *
     * @return array{oid_map:array<string,string>,vendor:string,model:string,name:string,points_total:int,points_mapped:int,skipped_writable:int}
     */
    public static function oidMapFromVertivNmsJson(string $jsonPath): array
    {
        if (!is_file($jsonPath)) {
            throw new RuntimeException('Vertiv template file not found: ' . $jsonPath);
        }
        $raw = file_get_contents($jsonPath);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            throw new RuntimeException('Invalid Vertiv JSON in ' . basename($jsonPath));
        }
        $vendor = trim((string)($data['make'] ?? $data['vendor'] ?? 'Vertiv')) ?: 'Vertiv';
        $model = trim((string)($data['model'] ?? $data['name'] ?? 'Liebert')) ?: 'Liebert';
        $name = trim((string)($data['name'] ?? ($vendor . ' ' . $model)));

        $points = $data['device']['collection']['outputPoints']
            ?? $data['collection']['outputPoints']
            ?? $data['outputPoints']
            ?? [];
        if (!is_array($points)) {
            $points = [];
        }

        $nameToKey = [
            'alarms present' => 'alarms_present',
            'control temp' => 'control_temp',
            'supply temp' => 'supply_temp',
            'return temp' => 'return_temp',
            'control humidity' => 'control_humidity',
            'return humidity' => 'return_humidity',
            'supply humidity' => 'supply_humidity',
            'system state' => 'system_state',
            'unit status' => 'system_state',
            'cooling' => 'cooling_state',
            'fan' => 'fan_state',
            'cooling capacity' => 'cooling_capacity_pct',
            'fan capacity' => 'fan_capacity_pct',
            'humidify' => 'humidify_state',
            'dehumidify' => 'dehumidify_state',
            'reheat' => 'reheat_state',
        ];

        $map = [
            'sysDescr' => '1.3.6.1.2.1.1.1.0',
            'sysUpTime' => '1.3.6.1.2.1.1.3.0',
            'sysObjectID' => '1.3.6.1.2.1.1.2.0',
        ];
        $skippedWritable = 0;
        $mapped = 0;

        foreach ($points as $pt) {
            if (!is_array($pt)) {
                continue;
            }
            if (!empty($pt['writable'])) {
                $skippedWritable++;
                continue;
            }
            $oid = trim((string)($pt['snmpConfig']['oid'] ?? $pt['oid'] ?? ''));
            if ($oid === '' || !preg_match('/^\d/', $oid)) {
                continue;
            }
            // Skip SET / control branches when name implies control
            $pname = trim((string)($pt['name'] ?? ''));
            $pl = strtolower($pname);
            if ($pl === '' || str_contains($pl, 'unit control') || str_contains($pl, 'setpoint write')) {
                $skippedWritable++;
                continue;
            }
            $key = $nameToKey[$pl] ?? null;
            if ($key === null) {
                // Prefer a stable slug for useful thermal points only
                if (!preg_match('/temp|humid|state|capacity|alarm|fan|cool|supply|return/i', $pname)) {
                    continue;
                }
                $key = preg_replace('/[^a-z0-9]+/i', '_', $pl) ?? '';
                $key = trim(strtolower((string)$key), '_');
                if ($key === '' || isset($map[$key])) {
                    continue;
                }
            }
            if (!isset($map[$key])) {
                $map[$key] = $oid;
                $mapped++;
            }
        }

        // Ensure core keys from pack if JSON missed them (DS present-value tree)
        $coreFallback = [
            'alarms_present' => '1.3.6.1.4.1.476.1.42.3.2.2.0',
            'control_temp' => '1.3.6.1.4.1.476.1.42.3.4.1.2.3.1.3.1',
            'supply_temp' => '1.3.6.1.4.1.476.1.42.3.4.1.2.3.1.3.2',
            'return_temp' => '1.3.6.1.4.1.476.1.42.3.4.1.2.3.1.3.3',
            'control_humidity' => '1.3.6.1.4.1.476.1.42.3.4.2.2.3.1.3.1',
            'return_humidity' => '1.3.6.1.4.1.476.1.42.3.4.2.2.3.1.3.2',
            'system_state' => '1.3.6.1.4.1.476.1.42.3.4.3.1.0',
            'cooling_capacity_pct' => '1.3.6.1.4.1.476.1.42.3.4.3.9.0',
            'fan_capacity_pct' => '1.3.6.1.4.1.476.1.42.3.4.3.16.0',
            'chilled_water_temp' => '1.3.6.1.4.1.476.1.42.3.4.1.2.3.1.50.7',
            'supply_temp_setpoint' => '1.3.6.1.4.1.476.1.42.3.4.1.2.3.1.6.2',
            'return_temp_setpoint' => '1.3.6.1.4.1.476.1.42.3.4.1.2.3.1.6.3',
            'fan_state' => '1.3.6.1.4.1.476.1.42.3.4.3.7.0',
            'free_cooling_state' => '1.3.6.1.4.1.476.1.42.3.4.3.20.0',
            'operating_mode' => '1.3.6.1.4.1.476.1.42.3.4.3.15.0',
        ];
        foreach ($coreFallback as $k => $oid) {
            if (!isset($map[$k])) {
                $map[$k] = $oid;
                $mapped++;
            }
        }

        return [
            'oid_map' => $map,
            'vendor' => $vendor,
            'model' => $model,
            'name' => $name,
            'points_total' => count($points),
            'points_mapped' => $mapped,
            'skipped_writable' => $skippedWritable,
        ];
    }

    /**
     * Install Vertiv NMS JSON as a site OID template (source=vertiv_nms).
     *
     * @return array{created?:bool,overwritten?:bool,exists?:bool,template_id:int,name:string,import:array}
     */
    public static function installVertivNmsFile(string $jsonPath, bool $overwrite = false): array
    {
        $import = self::oidMapFromVertivNmsJson($jsonPath);
        if (!class_exists('SnmpDiscover')) {
            require_once __DIR__ . '/SnmpDiscover.php';
        }
        $notes = sprintf(
            'Imported from Vertiv NMS template %s (%d points scanned, %d mapped, %d writable skipped). '
            . 'Condition OIDs need Unity VACM read access. Unit Control SETs are not included.',
            basename($jsonPath),
            (int)$import['points_total'],
            (int)$import['points_mapped'],
            (int)$import['skipped_writable']
        );
        $result = SnmpDiscover::saveSiteTemplate(
            (string)$import['vendor'],
            (string)$import['model'],
            $import['oid_map'],
            $overwrite,
            'vertiv_nms',
            substr($notes, 0, 500)
        );
        $result['import'] = $import;
        return $result;
    }

    public static function get(string $id): ?array
    {
        foreach (self::all() as $t) {
            if ($t['id'] === $id) {
                return $t;
            }
        }
        return null;
    }

    /**
     * Flatten oid_map to form field values (watts/amps/temp/uptime + extra JSON).
     * @return array{oid_uptime:string,oid_watts:string,oid_amps:string,oid_temp:string,oid_extra:array<string,string>,oid_map:array<string,string>}
     */
    public static function formFieldsFromTemplate(array $template): array
    {
        $map = [];
        foreach ($template['oid_map'] ?? [] as $k => $v) {
            $k = (string)$k;
            $v = trim((string)$v);
            if ($v === '') {
                continue;
            }
            $map[$k] = $v;
        }
        $amps = $map['amps'] ?? $map['amps_x10'] ?? '';
        $watts = $map['watts'] ?? '';
        $temp = $map['temperature'] ?? $map['temp'] ?? '';
        $uptime = $map['sysUpTime'] ?? $map['sysUpTime.0'] ?? '1.3.6.1.2.1.1.3.0';
        $extra = $map;
        unset($extra['watts'], $extra['amps'], $extra['amps_x10'], $extra['temperature'], $extra['temp'], $extra['sysUpTime']);
        return [
            'oid_uptime' => $uptime,
            'oid_watts' => $watts,
            'oid_amps' => $amps,
            'oid_amps_metric' => isset($map['amps_x10']) ? 'amps_x10' : 'amps',
            'oid_temp' => $temp,
            'oid_extra' => $extra,
            'oid_map' => $map,
        ];
    }

    /**
     * Build oid_map JSON-ready array from POST + optional template defaults.
     * @param array<string,mixed> $post
     * @return array<string,string>
     */
    public static function oidMapFromPost(array $post): array
    {
        $map = [];
        $templateId = trim((string)($post['oid_template'] ?? ''));
        if ($templateId !== '' && $templateId !== 'custom') {
            $tpl = self::get($templateId);
            if ($tpl) {
                foreach ($tpl['oid_map'] as $k => $v) {
                    $v = trim((string)$v);
                    if ($v !== '') {
                        $map[(string)$k] = $v;
                    }
                }
                $map['_template'] = $templateId;
            }
        }

        // Explicit form fields override template
        $uptime = trim((string)($post['oid_uptime'] ?? ''));
        $watts = trim((string)($post['oid_watts'] ?? ''));
        $amps = trim((string)($post['oid_amps'] ?? ''));
        $temp = trim((string)($post['oid_temp'] ?? ''));
        $ampsMetric = trim((string)($post['oid_amps_metric'] ?? 'amps'));
        if ($ampsMetric !== 'amps_x10') {
            $ampsMetric = 'amps';
        }

        if ($uptime !== '') {
            $map['sysUpTime'] = $uptime;
        } elseif (!isset($map['sysUpTime'])) {
            $map['sysUpTime'] = '1.3.6.1.2.1.1.3.0';
        }
        if ($watts !== '') {
            $map['watts'] = $watts;
        }
        if ($amps !== '') {
            // Prefer amps_x10 key when selected or when template used that name
            if ($ampsMetric === 'amps_x10' || (isset($map['amps_x10']) && !isset($post['oid_amps']))) {
                $map['amps_x10'] = $amps;
                unset($map['amps']);
            } else {
                $map['amps'] = $amps;
                // If form overrode template amps_x10 with plain amps field, clear x10 unless metric says x10
                if ($ampsMetric !== 'amps_x10') {
                    unset($map['amps_x10']);
                }
            }
        }
        if ($temp !== '') {
            $map['temperature'] = $temp;
        }

        // Drop empty values except we keep keys that have OIDs
        $out = [];
        foreach ($map as $k => $v) {
            if ($k === '_template') {
                $out[$k] = (string)$v;
                continue;
            }
            $v = trim((string)$v);
            if ($v !== '') {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** @return list<array{id:string,vendor:string,label:string,notes:string,oid_map:array}> */
    private static function builtinFallback(): array
    {
        return [
            [
                'id' => 'custom',
                'vendor' => 'Custom',
                'label' => 'Custom / blank',
                'notes' => 'Enter OIDs manually.',
                'oid_map' => [
                    'sysUpTime' => '1.3.6.1.2.1.1.3.0',
                ],
            ],
            [
                'id' => 'coldaisle_lab_agent',
                'vendor' => 'ColdAisle',
                'label' => 'PowerShell lab agent',
                'notes' => 'Enterprise test OIDs under 1.3.6.1.4.1.99999',
                'oid_map' => [
                    'sysDescr' => '1.3.6.1.2.1.1.1.0',
                    'sysUpTime' => '1.3.6.1.2.1.1.3.0',
                    'watts' => '1.3.6.1.4.1.99999.2.1.0',
                    'amps_x10' => '1.3.6.1.4.1.99999.2.2.0',
                ],
            ],
        ];
    }
}
