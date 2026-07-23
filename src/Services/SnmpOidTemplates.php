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

    /** @return list<array{id:string,vendor:string,label:string,notes:string,oid_map:array<string,string>}> */
    public static function all(): array
    {
        $out = [];
        foreach (self::load()['templates'] as $t) {
            if (!is_array($t) || empty($t['id'])) {
                continue;
            }
            $out[] = [
                'id' => (string)$t['id'],
                'vendor' => (string)($t['vendor'] ?? ''),
                'label' => (string)($t['label'] ?? $t['id']),
                'notes' => (string)($t['notes'] ?? ''),
                'oid_map' => is_array($t['oid_map'] ?? null) ? $t['oid_map'] : [],
            ];
        }
        return $out;
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
                'id' => 'windcim_lab_agent',
                'vendor' => 'WinDCIM',
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
