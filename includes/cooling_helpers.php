<?php
/**
 * Cooling & environmental monitoring — labels, ASHRAE guidance, field helpers.
 *
 * Guidance is based on ASHRAE TC 9.9 thermal envelopes (Recommended / A1–A4).
 * Operators choose how much to use: a single active/standby pair is valid;
 * multi-unit / multi-sensor layouts are supported without requiring cooling zones.
 */
declare(strict_types=1);

/** @return array<string,string> */
function cooling_unit_types(): array
{
    return [
        'crac' => 'CRAC (DX computer-room AC)',
        'crah' => 'CRAH (chilled-water air handler)',
        'in_row' => 'In-row cooler',
        'chiller' => 'Chiller',
        'chilled_water_pump' => 'Chilled-water pump',
        'ac_pump' => 'AC / condenser / secondary pump',
        'cdu' => 'CDU (coolant distribution)',
        'ahu' => 'AHU / make-up air',
        'other' => 'Other',
    ];
}

/** @return array<string,string> */
function cooling_unit_roles(): array
{
    return [
        'primary' => 'Primary (active)',
        'standby' => 'Standby',
        'shared' => 'Shared / N+1 pool',
        'unknown' => 'Unspecified',
    ];
}

/** @return array<string,string> */
function cooling_media(): array
{
    return [
        'dx' => 'Direct expansion (DX / refrigerant)',
        'chilled_water' => 'Chilled water',
        'glycol' => 'Glycol loop',
        'dual' => 'Dual / hybrid',
        'other' => 'Other',
    ];
}

/** @return array<string,string> */
function cooling_unit_statuses(): array
{
    return [
        'production' => 'In service',
        'standby' => 'Standby',
        'maintenance' => 'Maintenance',
        'offline' => 'Offline',
        'decommissioned' => 'Decommissioned',
    ];
}

/**
 * ASHRAE TC 9.9 class labels (simplified for UI).
 * @return array<string,string>
 */
function cooling_ashrae_classes(): array
{
    return [
        'recommended' => 'ASHRAE Recommended (enterprise IT)',
        'A1' => 'Allowable A1',
        'A2' => 'Allowable A2',
        'A3' => 'Allowable A3',
        'A4' => 'Allowable A4',
        'custom' => 'Custom / site-specific',
    ];
}

/**
 * Rough dry-bulb / RH guidance for display (not a compliance engine).
 * Values: dry-bulb °C low/high, RH % low/high (null = not specified in this summary).
 *
 * @return array{label:string,db_c:array{0:?float,1:?float},rh:array{0:?float,1:?float},notes:string}
 */
function cooling_ashrae_envelope(string $class): array
{
    return match ($class) {
        'recommended' => [
            'label' => 'Recommended',
            'db_c' => [18.0, 27.0],
            'rh' => [null, 60.0],
            'notes' => 'Typical enterprise target band (dry-bulb 18–27 °C; dew point / RH limits apply).',
        ],
        'A1' => [
            'label' => 'A1',
            'db_c' => [15.0, 32.0],
            'rh' => [20.0, 80.0],
            'notes' => 'Allowable envelope A1 (widest enterprise IT class in common use).',
        ],
        'A2' => [
            'label' => 'A2',
            'db_c' => [10.0, 35.0],
            'rh' => [20.0, 80.0],
            'notes' => 'Allowable envelope A2.',
        ],
        'A3' => [
            'label' => 'A3',
            'db_c' => [5.0, 40.0],
            'rh' => [8.0, 85.0],
            'notes' => 'Allowable envelope A3.',
        ],
        'A4' => [
            'label' => 'A4',
            'db_c' => [5.0, 45.0],
            'rh' => [8.0, 90.0],
            'notes' => 'Allowable envelope A4 (widest allowable class).',
        ],
        default => [
            'label' => 'Custom',
            'db_c' => [null, null],
            'rh' => [null, null],
            'notes' => 'Site-defined limits on each sensor.',
        ],
    };
}

/** @return array<string,string> */
function env_sensor_kinds(): array
{
    return [
        'temperature' => 'Temperature',
        'humidity' => 'Relative humidity',
        'temp_humidity' => 'Temperature + humidity (combo)',
        'dew_point' => 'Dew point',
        'differential_pressure' => 'Differential pressure',
        'airflow' => 'Airflow',
        'leak' => 'Leak / water detect',
        'other' => 'Other',
    ];
}

/** @return array<string,string> */
function env_sensor_hosts(): array
{
    return [
        'standalone' => 'Standalone probe / monitor',
        'device' => 'Device (env manager / NMC host)',
        'cooling_unit' => 'Mounted on cooling unit',
        'pdu' => 'PDU environmental port',
        'cabinet' => 'Cabinet-mounted (no host device)',
        'room' => 'Room / space (no host device)',
    ];
}

/**
 * Device types treated as environmental hosts (AP9340-class managers, expansion modules).
 * @return list<string>
 */
function env_device_types(): array
{
    return ['env_monitor', 'env_module'];
}

function env_is_env_device_type(?string $type): bool
{
    return in_array((string)$type, env_device_types(), true);
}

/**
 * Find active rack devices whose U range overlaps cabinet + position + height.
 *
 * @return list<array{device_id:int,label:string,device_type:string,position_u:int,u_height:int,manufacturer:?string,model:?string}>
 */
function env_find_devices_at_cabinet_u(int $cabinetId, int $positionU, int $uHeight = 1, int $excludeDeviceId = 0): array
{
    if ($cabinetId <= 0 || $positionU <= 0) {
        return [];
    }
    $uHeight = max(1, $uHeight);
    $end = $positionU + $uHeight - 1;
    $sql = 'SELECT device_id, label, device_type, position_u, u_height, manufacturer, model
            FROM devices
            WHERE is_active = 1 AND cabinet_id = ? AND position_u IS NOT NULL
              AND parent_device_id IS NULL';
    $params = [$cabinetId];
    if ($excludeDeviceId > 0) {
        $sql .= ' AND device_id <> ?';
        $params[] = $excludeDeviceId;
    }
    $sql .= ' ORDER BY position_u, label';
    try {
        $rows = Database::fetchAll($sql, $params);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $o) {
        $os = (int)$o['position_u'];
        $oe = $os + max(1, (int)($o['u_height'] ?? 1)) - 1;
        if ($positionU <= $oe && $end >= $os) {
            $out[] = [
                'device_id' => (int)$o['device_id'],
                'label' => (string)$o['label'],
                'device_type' => (string)($o['device_type'] ?? ''),
                'position_u' => $os,
                'u_height' => max(1, (int)($o['u_height'] ?? 1)),
                'manufacturer' => $o['manufacturer'] !== null ? (string)$o['manufacturer'] : null,
                'model' => $o['model'] !== null ? (string)$o['model'] : null,
            ];
        }
    }
    return $out;
}

/** @return array<string,string> */
function env_sensor_placements(): array
{
    return [
        'supply_air' => 'Supply air',
        'return_air' => 'Return air',
        'cold_aisle' => 'Cold aisle',
        'hot_aisle' => 'Hot aisle',
        'ambient' => 'Ambient / room',
        'underfloor' => 'Underfloor',
        'intake' => 'Equipment intake',
        'exhaust' => 'Equipment exhaust',
        'other' => 'Other',
    ];
}

function cooling_default_color(string $unitType): string
{
    return match ($unitType) {
        'crac', 'crah', 'in_row', 'ahu' => '#0ea5e9',
        'chiller' => '#0284c7',
        'chilled_water_pump', 'ac_pump' => '#0369a1',
        'cdu' => '#38bdf8',
        default => '#64748b',
    };
}

/**
 * @param array<string,mixed> $post
 * @return array<string,mixed>
 */
function cooling_unit_fields_from_post(array $post): array
{
    $type = (string)($post['unit_type'] ?? 'crac');
    if (!isset(cooling_unit_types()[$type])) {
        $type = 'other';
    }
    $role = (string)($post['unit_role'] ?? 'primary');
    if (!isset(cooling_unit_roles()[$role])) {
        $role = 'unknown';
    }
    $media = (string)($post['cooling_medium'] ?? 'dx');
    if (!isset(cooling_media()[$media])) {
        $media = 'other';
    }
    $status = (string)($post['status'] ?? 'production');
    if (!isset(cooling_unit_statuses()[$status])) {
        $status = 'production';
    }
    $ashrae = (string)($post['ashrae_class'] ?? 'recommended');
    if (!isset(cooling_ashrae_classes()[$ashrae])) {
        $ashrae = 'custom';
    }
    $standbyOf = isset($post['standby_of_id']) && $post['standby_of_id'] !== ''
        ? (int)$post['standby_of_id'] : null;
    if ($standbyOf !== null && $standbyOf <= 0) {
        $standbyOf = null;
    }

    $num = static function ($v): ?float {
        if ($v === null || $v === '') {
            return null;
        }
        return is_numeric($v) ? (float)$v : null;
    };
    $intOrNull = static function ($v): ?int {
        if ($v === null || $v === '') {
            return null;
        }
        return (int)$v;
    };

    return [
        'name' => trim((string)($post['name'] ?? '')),
        'unit_type' => $type,
        'unit_role' => $role,
        'standby_of_id' => $standbyOf,
        'room_id' => $intOrNull($post['room_id'] ?? null),
        'row_id' => $intOrNull($post['row_id'] ?? null),
        'manufacturer' => cooling_null_str($post['manufacturer'] ?? null),
        'model' => cooling_null_str($post['model'] ?? null),
        'serial_no' => cooling_null_str($post['serial_no'] ?? null),
        'asset_tag' => cooling_null_str($post['asset_tag'] ?? null),
        'primary_ip' => cooling_null_str($post['primary_ip'] ?? null),
        'hostname' => cooling_null_str($post['hostname'] ?? null),
        'warranty_provider' => cooling_null_str($post['warranty_provider'] ?? null),
        'warranty_end' => cooling_null_str($post['warranty_end'] ?? null),
        'install_date' => cooling_null_str($post['install_date'] ?? null),
        'manufacture_date' => cooling_null_str($post['manufacture_date'] ?? null),
        'cooling_medium' => $media,
        'rated_kw_cooling' => $num($post['rated_kw_cooling'] ?? null),
        'rated_tons' => $num($post['rated_tons'] ?? null),
        'rated_cfm' => $num($post['rated_cfm'] ?? null),
        'supply_temp_setpoint_c' => $num($post['supply_temp_setpoint_c'] ?? null),
        'return_temp_setpoint_c' => $num($post['return_temp_setpoint_c'] ?? null),
        'ashrae_class' => $ashrae,
        'status' => $status,
        'width_mm' => $intOrNull($post['width_mm'] ?? null) ?? 1200,
        'depth_mm' => $intOrNull($post['depth_mm'] ?? null) ?? 900,
        'height_mm' => $intOrNull($post['height_mm'] ?? null) ?? 2000,
        'color_hex' => cooling_null_str($post['color_hex'] ?? null) ?: cooling_default_color($type),
        'snmp_enabled' => !empty($post['snmp_enabled']) ? 1 : 0,
        'snmp_version' => cooling_null_str($post['snmp_version'] ?? null),
        'snmp_community' => cooling_null_str($post['snmp_community'] ?? null),
        'snmp_port' => $intOrNull($post['snmp_port'] ?? null) ?? 161,
        'snmp_site_template_id' => $intOrNull($post['snmp_site_template_id'] ?? null),
        'snmp_auto_poll' => !empty($post['snmp_auto_poll']) ? 1 : 0,
        'notes' => cooling_null_str($post['notes'] ?? null),
        'is_active' => isset($post['is_active']) ? (!empty($post['is_active']) ? 1 : 0) : 1,
    ];
}

function cooling_null_str(mixed $v): ?string
{
    if ($v === null) {
        return null;
    }
    $s = trim((string)$v);
    return $s === '' ? null : $s;
}

/**
 * Default unit string for a sensor kind.
 */
function env_sensor_default_unit(string $kind): string
{
    return match ($kind) {
        'temperature', 'dew_point' => '°C',
        'humidity' => '%RH',
        'temp_humidity' => '°C / %RH',
        'differential_pressure' => 'Pa',
        'airflow' => 'CFM',
        'leak' => 'state',
        default => '',
    };
}

/**
 * @param array<string,mixed> $post
 * @return array<string,mixed>
 */
function env_sensor_fields_from_post(array $post): array
{
    $kind = (string)($post['sensor_kind'] ?? 'temperature');
    if (!isset(env_sensor_kinds()[$kind])) {
        $kind = 'other';
    }
    $host = (string)($post['host_type'] ?? 'standalone');
    if (!isset(env_sensor_hosts()[$host])) {
        $host = 'standalone';
    }
    $placement = (string)($post['placement'] ?? 'ambient');
    if (!isset(env_sensor_placements()[$placement])) {
        $placement = 'other';
    }

    $num = static function ($v): ?float {
        if ($v === null || $v === '') {
            return null;
        }
        return is_numeric($v) ? (float)$v : null;
    };
    $intOrNull = static function ($v): ?int {
        if ($v === null || $v === '') {
            return null;
        }
        $n = (int)$v;
        return $n > 0 ? $n : null;
    };

    $unit = cooling_null_str($post['unit'] ?? null) ?: env_sensor_default_unit($kind);

    $fields = [
        'name' => trim((string)($post['name'] ?? '')),
        'sensor_kind' => $kind,
        'host_type' => $host,
        'cooling_unit_id' => null,
        'pdu_id' => null,
        'device_id' => null,
        'cabinet_id' => null,
        'room_id' => $intOrNull($post['room_id'] ?? null),
        'location_label' => cooling_null_str($post['location_label'] ?? null),
        'placement' => $placement,
        'unit' => $unit !== '' ? $unit : null,
        'ashrae_metric' => cooling_null_str($post['ashrae_metric'] ?? null),
        'warn_low' => $num($post['warn_low'] ?? null),
        'warn_high' => $num($post['warn_high'] ?? null),
        'crit_low' => $num($post['crit_low'] ?? null),
        'crit_high' => $num($post['crit_high'] ?? null),
        'snmp_oid' => cooling_null_str($post['snmp_oid'] ?? null),
        'snmp_index' => cooling_null_str($post['snmp_index'] ?? null),
        'snmp_site_template_id' => $intOrNull($post['snmp_site_template_id'] ?? null),
        'pos_x' => $num($post['pos_x'] ?? null),
        'pos_y' => $num($post['pos_y'] ?? null),
        'pos_z' => $num($post['pos_z'] ?? null),
        'notes' => cooling_null_str($post['notes'] ?? null),
        'is_active' => isset($post['is_active']) ? (!empty($post['is_active']) ? 1 : 0) : 1,
    ];

    // Host foreign keys — only the matching host type is populated
    switch ($host) {
        case 'cooling_unit':
            $fields['cooling_unit_id'] = $intOrNull($post['cooling_unit_id'] ?? null);
            break;
        case 'pdu':
            $fields['pdu_id'] = $intOrNull($post['pdu_id'] ?? null);
            break;
        case 'device':
            $fields['device_id'] = $intOrNull($post['device_id'] ?? null);
            // Optional cabinet for rack location of the host / probe
            $fields['cabinet_id'] = $intOrNull($post['cabinet_id'] ?? null);
            break;
        case 'cabinet':
            $fields['cabinet_id'] = $intOrNull($post['cabinet_id'] ?? null);
            break;
        case 'room':
        case 'standalone':
        default:
            // room_id already set; standalone may still have room for placement
            break;
    }

    return $fields;
}

/**
 * Human status for a sensor last value vs thresholds.
 *
 * @return 'ok'|'warn'|'crit'|'unknown'
 */
function env_sensor_threshold_status(?float $value, array $sensor): string
{
    if ($value === null) {
        return 'unknown';
    }
    $critLow = isset($sensor['crit_low']) && $sensor['crit_low'] !== '' && $sensor['crit_low'] !== null
        ? (float)$sensor['crit_low'] : null;
    $critHigh = isset($sensor['crit_high']) && $sensor['crit_high'] !== '' && $sensor['crit_high'] !== null
        ? (float)$sensor['crit_high'] : null;
    $warnLow = isset($sensor['warn_low']) && $sensor['warn_low'] !== '' && $sensor['warn_low'] !== null
        ? (float)$sensor['warn_low'] : null;
    $warnHigh = isset($sensor['warn_high']) && $sensor['warn_high'] !== '' && $sensor['warn_high'] !== null
        ? (float)$sensor['warn_high'] : null;

    if ($critLow !== null && $value < $critLow) {
        return 'crit';
    }
    if ($critHigh !== null && $value > $critHigh) {
        return 'crit';
    }
    if ($warnLow !== null && $value < $warnLow) {
        return 'warn';
    }
    if ($warnHigh !== null && $value > $warnHigh) {
        return 'warn';
    }
    return 'ok';
}
