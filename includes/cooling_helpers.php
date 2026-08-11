?php
/**
 * Cooling & environmental monitoring â€” labels, ASHRAE guidance, field helpers.
 *
 * Guidance is based on ASHRAE TC 9.9 thermal envelopes (Recommended / A1â€“A4).
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
 * Values: dry-bulb Â°C low/high, RH % low/high (null = not specified in this summary).
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
            'notes' => 'Typical enterprise target band (dry-bulb 18â€“27 Â°C; dew point / RH limits apply).',
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
        // Setpoints stored in Â°C; form posts site display unit
        'supply_temp_setpoint_c' => class_exists('TempUnitService')
            ? TempUnitService::postToC($post['supply_temp_setpoint_c'] ?? null)
            : $num($post['supply_temp_setpoint_c'] ?? null),
        'return_temp_setpoint_c' => class_exists('TempUnitService')
            ? TempUnitService::postToC($post['return_temp_setpoint_c'] ?? null)
            : $num($post['return_temp_setpoint_c'] ?? null),
        'ashrae_class' => $ashrae,
        'status' => $status,
        'width_mm' => $intOrNull($post['width_mm'] ?? null) ?? 1200,
        'depth_mm' => $intOrNull($post['depth_mm'] ?? null) ?? 900,
        'height_mm' => $intOrNull($post['height_mm'] ?? null) ?? 2000,
        'color_hex' => cooling_null_str($post['color_hex'] ?? null) ?: cooling_default_color($type),
        'snmp_enabled' => !empty($post['snmp_enabled']) ? 1 : 0,
        'snmp_version' => cooling_null_str($post['snmp_version'] ?? null) ?? '2c',
        'snmp_community' => cooling_null_str($post['snmp_community'] ?? null),
        'snmp_port' => $intOrNull($post['snmp_port'] ?? null) ?? 161,
        'snmp_v3_profile_id' => $intOrNull($post['snmp_v3_profile_id'] ?? null),
        'snmp_v3_sec_level' => cooling_null_str($post['snmp_v3_sec_level'] ?? null),
        'snmp_security_name' => cooling_null_str($post['snmp_security_name'] ?? null),
        'snmp_auth_protocol' => cooling_null_str($post['snmp_auth_protocol'] ?? null),
        // Passphrases: empty string means "keep previous" on update (resolved later)
        'snmp_auth_passphrase' => array_key_exists('snmp_auth_passphrase', $post)
            ? (string)($post['snmp_auth_passphrase'] ?? '')
            : null,
        'snmp_priv_protocol' => cooling_null_str($post['snmp_priv_protocol'] ?? null),
        'snmp_priv_passphrase' => array_key_exists('snmp_priv_passphrase', $post)
            ? (string)($post['snmp_priv_passphrase'] ?? '')
            : null,
        'snmp_context' => cooling_null_str($post['snmp_context'] ?? null),
        'snmp_site_template_id' => $intOrNull($post['snmp_site_template_id'] ?? null),
        'snmp_auto_poll' => !empty($post['snmp_auto_poll']) ? 1 : 0,
        'notes' => cooling_null_str($post['notes'] ?? null),
        'is_active' => isset($post['is_active']) ? (!empty($post['is_active']) ? 1 : 0) : 1,
    ];
}

/**
 * Apply SNMPv3 profile + seal secrets for cooling_units save.
 * Blank community/passphrases keep previous sealed values on update.
 * When a profile is selected, profile passphrases replace unit secrets.
 *
 * @param array<string,mixed> $row
 * @param array<string,mixed>|null $prev existing row (update) or null (create)
 * @return array<string,mixed>
 */
function cooling_unit_finalize_snmp(array $row, ?array $prev = null): array
{
    $ver = strtolower(trim((string)($row['snmp_version'] ?? '2c')));
    if ($ver === '') {
        $ver = '2c';
    }
    $row['snmp_version'] = $ver;
    $wantsV3 = $ver === '3';

    if (!$wantsV3) {
        $row['snmp_v3_profile_id'] = null;
    }

    $authFromProfile = false;
    $privFromProfile = false;

    // Apply credential profile (same rules as PDUs / devices)
    if ($wantsV3 && !empty($row['snmp_v3_profile_id'])) {
        try {
            $prof = Database::fetchOne(
                'SELECT * FROM snmp_v3_profiles WHERE profile_id = ? AND is_active = 1',
                [(int)$row['snmp_v3_profile_id']]
            );
            if ($prof) {
                $secName = trim((string)($prof['security_name'] ?? ''));
                if ($secName !== '') {
                    $row['snmp_security_name'] = $secName;
                }
                $lvl = trim((string)($prof['security_level'] ?? ''));
                if ($lvl !== '') {
                    $row['snmp_v3_sec_level'] = $lvl;
                }
                $authProto = trim((string)($prof['auth_protocol'] ?? ''));
                if ($authProto !== '') {
                    $row['snmp_auth_protocol'] = strtoupper($authProto);
                }
                $privProto = trim((string)($prof['priv_protocol'] ?? ''));
                if ($privProto !== '') {
                    $row['snmp_priv_protocol'] = strtoupper($privProto);
                }
                $ctx = $prof['context_name'] ?? null;
                $row['snmp_context'] = ($ctx === null || trim((string)$ctx) === '')
                    ? null
                    : trim((string)$ctx);
                // Profile passphrases are already sealed in DB â€” copy as-is
                if (!empty($prof['auth_passphrase'])) {
                    $row['snmp_auth_passphrase'] = $prof['auth_passphrase'];
                    $authFromProfile = true;
                }
                if (!empty($prof['priv_passphrase'])) {
                    $row['snmp_priv_passphrase'] = $prof['priv_passphrase'];
                    $privFromProfile = true;
                }
            }
        } catch (Throwable $e) {
            App::log('Cooling unit SNMPv3 profile apply failed: ' . $e->getMessage(), 'warning');
        }
    }

    if (!empty($row['snmp_auth_protocol'])) {
        $row['snmp_auth_protocol'] = strtoupper((string)$row['snmp_auth_protocol']);
    }
    if (!empty($row['snmp_priv_protocol'])) {
        $row['snmp_priv_protocol'] = strtoupper((string)$row['snmp_priv_protocol']);
    }

    $authPosted = $row['snmp_auth_passphrase'];
    $privPosted = $row['snmp_priv_passphrase'];
    $commPosted = $row['snmp_community'] ?? null;

    // Community: blank on update keeps previous sealed value
    if ($prev && ($commPosted === null || $commPosted === '') && !empty($prev['snmp_community'])) {
        $row['snmp_community'] = $prev['snmp_community'];
    }

    // Passphrases: profile wins; else blank keeps previous; else create null
    if (!$authFromProfile) {
        if (($authPosted === null || $authPosted === '') && $prev && !empty($prev['snmp_auth_passphrase'])) {
            $row['snmp_auth_passphrase'] = $prev['snmp_auth_passphrase'];
        } elseif ($authPosted === null || $authPosted === '') {
            $row['snmp_auth_passphrase'] = null;
        }
    }
    if (!$privFromProfile) {
        if (($privPosted === null || $privPosted === '') && $prev && !empty($prev['snmp_priv_passphrase'])) {
            $row['snmp_priv_passphrase'] = $prev['snmp_priv_passphrase'];
        } elseif ($privPosted === null || $privPosted === '') {
            $row['snmp_priv_passphrase'] = null;
        }
    }

    // Seal plaintext secrets (already-sealed values are left alone by Crypto::sealFields)
    if (class_exists('Crypto')) {
        $row = Crypto::sealFields($row, [
            'snmp_community',
            'snmp_auth_passphrase',
            'snmp_priv_passphrase',
        ]);
    }

    return $row;
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
    if (class_exists('TempUnitService')) {
        return TempUnitService::defaultUnitForKind($kind);
    }
    return match ($kind) {
        'temperature', 'dew_point' => 'Â°C',
        'humidity' => '%RH',
        'temp_humidity' => 'Â°C / %RH',
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

    // Prefer site temp unit for temperature kinds; allow explicit unit override
    $unitPosted = cooling_null_str($post['unit'] ?? null);
    if ($unitPosted === null || $unitPosted === '') {
        $unit = env_sensor_default_unit($kind);
    } else {
        $unit = $unitPosted;
    }

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

    // Thresholds entered in site display unit â†’ store Â°C for temperature kinds
    if (class_exists('TempUnitService')) {
        $fields = TempUnitService::thresholdsDisplayToStorage($fields, $kind);
        if (TempUnitService::isTempKind($kind) && ($unitPosted === null || $unitPosted === '')) {
            $fields['unit'] = TempUnitService::defaultUnitForKind($kind);
        }
    }

    // Host foreign keys â€” only the matching host type is populated
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
 * Display label for a sensor's host (device name, PDU name, â€¦).
 * Expects optional join columns: device_label, cooling_unit_name, pdu_name, cabinet_name, room_name.
 *
 * @param array<string,mixed> $sensor
 * @param array<string,string> $hostTypes from env_sensor_hosts()
 */
function env_sensor_host_display(array $sensor, array $hostTypes = []): string
{
    if ($hostTypes === []) {
        $hostTypes = env_sensor_hosts();
    }
    $type = (string)($sensor['host_type'] ?? 'standalone');
    $typeLabel = $hostTypes[$type] ?? $type;

    return match ($type) {
        'device' => trim((string)($sensor['device_label'] ?? '')) !== ''
            ? (string)$sensor['device_label']
            : ($typeLabel . (!empty($sensor['device_id']) ? ' #' . (int)$sensor['device_id'] : '')),
        'cooling_unit' => trim((string)($sensor['cooling_unit_name'] ?? '')) !== ''
            ? (string)$sensor['cooling_unit_name']
            : $typeLabel,
        'pdu' => trim((string)($sensor['pdu_name'] ?? '')) !== ''
            ? (string)$sensor['pdu_name']
            : $typeLabel,
        'cabinet' => trim((string)($sensor['cabinet_name'] ?? '')) !== ''
            ? (string)$sensor['cabinet_name']
            : $typeLabel,
        'room' => trim((string)($sensor['room_name'] ?? '')) !== ''
            ? (string)$sensor['room_name']
            : $typeLabel,
        default => $typeLabel,
    };
}

/**
 * Short kind label for dense tables.
 */
function env_sensor_kind_short(string $kind, array $kinds = []): string
{
    if ($kinds === []) {
        $kinds = env_sensor_kinds();
    }
    return match ($kind) {
        'temp_humidity' => 'Temp + RH',
        'temperature' => 'Temp',
        'humidity' => 'Humidity',
        'dew_point' => 'Dew point',
        'differential_pressure' => 'Î”P',
        'airflow' => 'Airflow',
        'leak' => 'Leak',
        default => $kinds[$kind] ?? $kind,
    };
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


/**
 * Promote known keys from cooling unit last_poll_json into a compact snapshot.
 * Accepts raw JSON string or decoded array (full snapshot or metrics-only).
 *
 * @param mixed $jsonOrArray
 * @return array{
 *   has_data:bool,
 *   polled_at:?string,
 *   supply_temp:?float,
 *   return_temp:?float,
 *   control_temp:?float,
 *   humidity:?float,
 *   system_state:?string,
 *   cooling_capacity_pct:?float,
 *   fan_capacity_pct:?float,
 *   alarms_present:?float,
 *   extras:array<string,scalar|null>,
 *   display:list<array{label:string,value:string,key:string}>
 * }
 */
function cooling_poll_snapshot_promote($jsonOrArray): array
{
    $empty = [
        'has_data' => false,
        'polled_at' => null,
        'supply_temp' => null,
        'return_temp' => null,
        'control_temp' => null,
        'humidity' => null,
        'system_state' => null,
        'cooling_capacity_pct' => null,
        'fan_capacity_pct' => null,
        'alarms_present' => null,
        'extras' => [],
        'display' => [],
    ];
    if ($jsonOrArray === null || $jsonOrArray === '') {
        return $empty;
    }
    if (is_string($jsonOrArray)) {
        $decoded = json_decode($jsonOrArray, true);
    } elseif (is_array($jsonOrArray)) {
        $decoded = $jsonOrArray;
    } else {
        return $empty;
    }
    if (!is_array($decoded)) {
        return $empty;
    }
    $metrics = [];
    if (isset($decoded['metrics']) && is_array($decoded['metrics'])) {
        $metrics = $decoded['metrics'];
    } else {
        foreach ($decoded as $k => $v) {
            if (is_string($k) && (is_scalar($v) || $v === null)
                && !in_array($k, ['polled_at', 'template_id', 'template_name', 'ok', 'failed'], true)
            ) {
                $metrics[$k] = $v;
            }
        }
    }
    if (!$metrics) {
        $empty['polled_at'] = isset($decoded['polled_at']) ? (string)$decoded['polled_at'] : null;
        return $empty;
    }

    $norm = static function (string $k): string {
        $k = strtolower(trim($k));
        $k = str_replace(['-', ' ', '.', '/'], '_', $k);
        return preg_replace('/[^a-z0-9_]+/', '', $k) ?? $k;
    };

    /** @var array<string,mixed> $byNorm */
    $byNorm = [];
    foreach ($metrics as $k => $v) {
        $byNorm[$norm((string)$k)] = $v;
        $byNorm[(string)$k] = $v;
    }

    $pick = static function (array $candidates) use ($byNorm, $norm) {
        foreach ($candidates as $c) {
            $n = $norm($c);
            if (array_key_exists($n, $byNorm) && $byNorm[$n] !== null && $byNorm[$n] !== '') {
                return $byNorm[$n];
            }
            if (array_key_exists($c, $byNorm) && $byNorm[$c] !== null && $byNorm[$c] !== '') {
                return $byNorm[$c];
            }
        }
        foreach ($candidates as $c) {
            $frag = $norm($c);
            if ($frag === '') {
                continue;
            }
            foreach ($byNorm as $nk => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                if (is_string($nk) && str_contains($nk, $frag)) {
                    return $v;
                }
            }
        }
        return null;
    };

    $num = static function ($v): ?float {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return (float)$v;
        }
        if (is_string($v) && preg_match('/[-+]?\d*\.?\d+/', $v, $m)) {
            return (float)$m[0];
        }
        return null;
    };

    $supply = $num($pick([
        'supply_temp', 'supply_temperature', 'supply_air_temp', 'sat',
        'leaving_air_temp', 'discharge_temp', 'supplytemp',
    ]));
    $return = $num($pick([
        'return_temp', 'return_temperature', 'return_air_temp', 'rat',
        'entering_air_temp', 'returntemp',
    ]));
    $control = $num($pick([
        'control_temp', 'control_temperature', 'space_temp', 'room_temp',
    ]));
    $humidity = $num($pick([
        'return_humidity', 'control_humidity', 'humidity', 'rh',
        'relative_humidity', 'return_rh', 'space_humidity',
    ]));
    $stateRaw = $pick([
        'system_state', 'unit_state', 'operating_state', 'mode',
        'status', 'on_off', 'unit_status',
    ]);
    $state = null;
    if ($stateRaw !== null && $stateRaw !== '') {
        if (is_numeric($stateRaw)) {
            $map = [0 => 'Off', 1 => 'On', 2 => 'Standby', 3 => 'Alarm'];
            $i = (int)$stateRaw;
            $state = $map[$i] ?? ('State ' . $i);
        } else {
            $state = (string)$stateRaw;
        }
    }
    $coolCap = $num($pick([
        'cooling_capacity', 'cooling_capacity_pct', 'cool_capacity',
        'capacity_pct', 'cooling_pct',
    ]));
    $fanCap = $num($pick([
        'fan_capacity', 'fan_capacity_pct', 'fan_speed', 'fan_pct',
    ]));
    $alarms = $num($pick([
        'alarms_present', 'alarm_count', 'alarms', 'active_alarms',
    ]));

    $fmtTemp = static function (?float $c): string {
        if ($c === null) {
            return '—';
        }
        // Large values may already be Fahrenheit from some vendor maps
        if (class_exists('TempUnitService') && $c <= 60.0) {
            return TempUnitService::format($c, 1);
        }
        if (class_exists('TempUnitService') && $c > 60.0) {
            $unit = method_exists('TempUnitService', 'siteUnit') ? TempUnitService::siteUnit() : 'C';
            if ($unit === 'F' || $unit === 'f') {
                return rtrim(rtrim(sprintf('%.1F', $c), '0'), '.') . ' °F';
            }
            $c2 = ($c - 32.0) * 5.0 / 9.0;
            return TempUnitService::format($c2, 1);
        }
        return rtrim(rtrim(sprintf('%.1F', $c), '0'), '.') . ' °C';
    };

    $display = [];
    if ($supply !== null) {
        $display[] = ['label' => 'Supply', 'value' => $fmtTemp($supply), 'key' => 'supply_temp'];
    }
    if ($return !== null) {
        $display[] = ['label' => 'Return', 'value' => $fmtTemp($return), 'key' => 'return_temp'];
    }
    if ($control !== null && $control !== $supply && $control !== $return) {
        $display[] = ['label' => 'Control', 'value' => $fmtTemp($control), 'key' => 'control_temp'];
    }
    if ($humidity !== null) {
        $display[] = [
            'label' => 'Humidity',
            'value' => rtrim(rtrim(sprintf('%.1F', $humidity), '0'), '.') . ' %RH',
            'key' => 'humidity',
        ];
    }
    if ($state !== null) {
        $display[] = ['label' => 'State', 'value' => $state, 'key' => 'system_state'];
    }
    if ($coolCap !== null) {
        $display[] = [
            'label' => 'Cooling %',
            'value' => rtrim(rtrim(sprintf('%.0F', $coolCap), '0'), '.') . '%',
            'key' => 'cooling_capacity_pct',
        ];
    }
    if ($fanCap !== null) {
        $display[] = [
            'label' => 'Fan %',
            'value' => rtrim(rtrim(sprintf('%.0F', $fanCap), '0'), '.') . '%',
            'key' => 'fan_capacity_pct',
        ];
    }
    if ($alarms !== null) {
        $display[] = [
            'label' => 'Alarms',
            'value' => rtrim(rtrim(sprintf('%.0F', $alarms), '0'), '.'),
            'key' => 'alarms_present',
        ];
    }

    return [
        'has_data' => $display !== [],
        'polled_at' => isset($decoded['polled_at']) ? (string)$decoded['polled_at'] : null,
        'supply_temp' => $supply,
        'return_temp' => $return,
        'control_temp' => $control,
        'humidity' => $humidity,
        'system_state' => $state,
        'cooling_capacity_pct' => $coolCap,
        'fan_capacity_pct' => $fanCap,
        'alarms_present' => $alarms,
        'extras' => [],
        'display' => $display,
    ];
}

