<?php
/**
 * ColdAisle - Lightweight schema upgrades for existing installs
 */
declare(strict_types=1);

class Schema
{
    private static bool $ensured = false;

    public static function ensure(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        try {
            self::ensureColumn(
                'datacenters',
                'north_edge',
                "NVARCHAR(10) NOT NULL CONSTRAINT DF_datacenters_north_edge DEFAULT 'top'"
            );
            self::ensureColumn('cabinet_rows', 'zone_id', 'INT NULL');
            self::ensureColumn('cabinet_rows', 'color_hex', 'NVARCHAR(7) NULL');

            // Departments: color for rack outlines + UI
            self::ensureColumn(
                'departments',
                'color_hex',
                "NVARCHAR(7) NOT NULL CONSTRAINT DF_departments_color DEFAULT '#3b82f6'"
            );
            self::ensureTable(
                'department_group_maps',
                "CREATE TABLE department_group_maps (
                    map_id INT IDENTITY(1,1) PRIMARY KEY,
                    department_id INT NOT NULL,
                    auth_source NVARCHAR(20) NOT NULL,
                    group_id NVARCHAR(255) NOT NULL,
                    group_name NVARCHAR(255) NULL,
                    is_active BIT NOT NULL CONSTRAINT DF_dgm_active DEFAULT 1,
                    notes NVARCHAR(255) NULL,
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_dgm_created DEFAULT SYSUTCDATETIME()
                )"
            );

            // Device inventory expansions
            $deviceCols = [
                'parent_device_id' => 'INT NULL',
                'manufacture_date' => 'DATE NULL',
                'weight_kg' => 'DECIMAL(10,2) NULL',
                'num_data_ports' => 'INT NULL',
                'num_power_ports' => 'INT NULL',
                'warranty_provider' => 'NVARCHAR(150) NULL',
                'tags' => 'NVARCHAR(500) NULL',
                'snmp_version' => 'NVARCHAR(10) NULL',
                'snmp_community' => 'NVARCHAR(100) NULL',
                'snmp_fail_count' => 'INT NOT NULL CONSTRAINT DF_devices_snmp_fail DEFAULT 0',
                'snmp_v3_profile_id' => 'INT NULL',
                'snmp_v3_user' => 'NVARCHAR(100) NULL',
                'snmp_v3_sec_level' => 'NVARCHAR(30) NULL',
                'snmp_v3_auth_proto' => 'NVARCHAR(20) NULL',
                'snmp_v3_auth_pass' => 'NVARCHAR(255) NULL',
                'snmp_v3_priv_proto' => 'NVARCHAR(20) NULL',
                'snmp_v3_priv_pass' => 'NVARCHAR(255) NULL',
                'snmp_v3_context' => 'NVARCHAR(100) NULL',
                // Site OID template (discovered or manual) — OIDs stored once, not per device
                'snmp_site_template_id' => 'INT NULL',
                'snmp_auto_poll' => 'BIT NOT NULL CONSTRAINT DF_devices_snmp_auto DEFAULT 0',
                'snmp_last_poll_at' => 'DATETIME2 NULL',
                'snmp_last_poll_watts' => 'DECIMAL(18,4) NULL',
                'snmp_last_poll_amps' => 'DECIMAL(18,4) NULL',
            ];
            foreach ($deviceCols as $col => $def) {
                self::ensureColumn('devices', $col, $def);
            }

            self::ensureTable(
                'snmp_site_oid_templates',
                "CREATE TABLE snmp_site_oid_templates (
                    template_id INT IDENTITY(1,1) PRIMARY KEY,
                    name NVARCHAR(150) NOT NULL,
                    vendor NVARCHAR(100) NULL,
                    model NVARCHAR(100) NULL,
                    oid_map NVARCHAR(MAX) NOT NULL CONSTRAINT DF_snmp_site_oidmap DEFAULT '{}',
                    source NVARCHAR(30) NOT NULL CONSTRAINT DF_snmp_site_src DEFAULT 'discovered',
                    notes NVARCHAR(500) NULL,
                    is_active BIT NOT NULL CONSTRAINT DF_snmp_site_active DEFAULT 1,
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_snmp_site_created DEFAULT SYSUTCDATETIME(),
                    updated_at DATETIME2 NOT NULL CONSTRAINT DF_snmp_site_updated DEFAULT SYSUTCDATETIME()
                )"
            );
            // Optional link from poll targets to a site OID template (shared map)
            try {
                $hasTargets = Database::fetchValue(
                    "SELECT 1 FROM sys.tables WHERE name = 'snmp_targets' AND SCHEMA_NAME(schema_id) = 'dbo'"
                );
                if ($hasTargets) {
                    self::ensureColumn('snmp_targets', 'site_template_id', 'INT NULL');
                }
            } catch (Throwable $e) {
                // ignore — targets table may lag behind on partial installs
            }

            self::ensureTable(
                'snmp_v3_profiles',
                "CREATE TABLE snmp_v3_profiles (
                    profile_id INT IDENTITY(1,1) PRIMARY KEY,
                    name NVARCHAR(100) NOT NULL,
                    security_name NVARCHAR(100) NOT NULL,
                    security_level NVARCHAR(30) NOT NULL CONSTRAINT DF_snmp_prof_lvl DEFAULT 'authPriv',
                    auth_protocol NVARCHAR(20) NULL,
                    auth_passphrase NVARCHAR(255) NULL,
                    priv_protocol NVARCHAR(20) NULL,
                    priv_passphrase NVARCHAR(255) NULL,
                    context_name NVARCHAR(100) NULL,
                    notes NVARCHAR(500) NULL,
                    is_active BIT NOT NULL CONSTRAINT DF_snmp_prof_active DEFAULT 1,
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_snmp_prof_created DEFAULT SYSUTCDATETIME(),
                    updated_at DATETIME2 NOT NULL CONSTRAINT DF_snmp_prof_updated DEFAULT SYSUTCDATETIME()
                )"
            );

            self::ensureTable(
                'device_notes',
                "CREATE TABLE device_notes (
                    note_id INT IDENTITY(1,1) PRIMARY KEY,
                    device_id INT NOT NULL,
                    user_id INT NULL,
                    username NVARCHAR(100) NULL,
                    note_text NVARCHAR(MAX) NOT NULL,
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_device_notes_created DEFAULT SYSUTCDATETIME()
                )"
            );

            // PDU mount + community + v3 level + multi-phase electrical
            $pduCols = [
                'mount_style' => "NVARCHAR(20) NOT NULL CONSTRAINT DF_pdus_mount DEFAULT 'vertical_rear'",
                'position_u' => 'INT NULL',
                'u_height' => 'INT NULL',
                'snmp_community' => 'NVARCHAR(100) NULL',
                'snmp_v3_sec_level' => 'NVARCHAR(30) NULL',
                'phases' => 'INT NOT NULL CONSTRAINT DF_pdus_phases DEFAULT 1',
                'phase_wiring' => "NVARCHAR(30) NULL CONSTRAINT DF_pdus_phase_wiring DEFAULT 'single'",
                'input_voltage' => 'INT NULL',
                'input_voltage_ln' => 'INT NULL',
                'output_voltage' => 'INT NULL',
                'output_voltage_ln' => 'INT NULL',
                'sync_zone_voltage' => 'BIT NOT NULL CONSTRAINT DF_pdus_sync_zone DEFAULT 1',
                'output_mode' => "NVARCHAR(20) NOT NULL CONSTRAINT DF_pdus_output_mode DEFAULT 'outlets'",
                'num_breaker_slots' => 'INT NULL',
                'breaker_columns' => 'INT NULL',
                'breaker_layout' => "NVARCHAR(40) NULL CONSTRAINT DF_pdus_brk_layout DEFAULT 'odd_right_even_left'",
                'snmp_v3_profile_id' => 'INT NULL',
                // Site OID template (discovered Vendor+Model) shared across same-model PDUs
                'snmp_site_template_id' => 'INT NULL',
                // Include in SNMP scheduler (poll_snmp.php) when a site template is assigned
                'snmp_auto_poll' => 'BIT NOT NULL CONSTRAINT DF_pdus_snmp_auto DEFAULT 0',
                // Floor plan placement for row/room PDUs
                'room_id' => 'INT NULL',
                'pos_x' => 'DECIMAL(10,3) NULL',
                'pos_y' => 'DECIMAL(10,3) NULL',
                'pos_z' => 'DECIMAL(10,3) NULL',
                'rotation_deg' => 'DECIMAL(8,2) NULL',
                'front_facing' => "NVARCHAR(10) NULL",
                'width_mm' => 'INT NULL',
                'depth_mm' => 'INT NULL',
                'height_mm' => 'INT NULL',
                'color_hex' => 'NVARCHAR(7) NULL',
            ];
            foreach ($pduCols as $col => $def) {
                self::ensureColumn('pdus', $col, $def);
            }
            self::ensureColumn('pdu_outlets', 'rated_amps', 'DECIMAL(8,2) NULL');
            self::ensureColumn('pdu_outlets', 'device_power_supply_id', 'INT NULL');

            self::ensureTable(
                'pdu_breakers',
                "CREATE TABLE pdu_breakers (
                    breaker_id INT IDENTITY(1,1) PRIMARY KEY,
                    pdu_id INT NOT NULL,
                    breaker_number INT NOT NULL,
                    label NVARCHAR(100) NULL,
                    slots_json NVARCHAR(500) NOT NULL CONSTRAINT DF_pdu_brk_slots DEFAULT '[]',
                    slot_start INT NULL,
                    slot_end INT NULL,
                    rated_amps DECIMAL(8,2) NULL,
                    phase NVARCHAR(20) NULL,
                    connected_cabinet_id INT NULL,
                    connected_device_id INT NULL,
                    notes NVARCHAR(255) NULL
                )"
            );
            self::ensureColumn('pdu_breakers', 'slots_json', "NVARCHAR(500) NULL");

            self::ensureTable(
                'device_power_supplies',
                "CREATE TABLE device_power_supplies (
                    power_supply_id INT IDENTITY(1,1) PRIMARY KEY,
                    device_id INT NOT NULL,
                    name NVARCHAR(100) NOT NULL CONSTRAINT DF_dps_name DEFAULT 'PSU',
                    watts DECIMAL(10,2) NULL,
                    connector_type NVARCHAR(50) NULL,
                    pdu_id INT NULL,
                    pdu_outlet_id INT NULL,
                    sort_order INT NOT NULL CONSTRAINT DF_dps_sort DEFAULT 0,
                    notes NVARCHAR(255) NULL
                )"
            );

            // Disposal / decommission workflow
            self::ensureTable(
                'disposal_vendors',
                "CREATE TABLE disposal_vendors (
                    vendor_id INT IDENTITY(1,1) PRIMARY KEY,
                    name NVARCHAR(150) NOT NULL,
                    vendor_type NVARCHAR(40) NOT NULL CONSTRAINT DF_dv_type DEFAULT 'itad',
                    contact_name NVARCHAR(150) NULL,
                    contact_email NVARCHAR(255) NULL,
                    contact_phone NVARCHAR(50) NULL,
                    website NVARCHAR(255) NULL,
                    certifications NVARCHAR(255) NULL,
                    address NVARCHAR(500) NULL,
                    notes NVARCHAR(MAX) NULL,
                    is_active BIT NOT NULL CONSTRAINT DF_dv_active DEFAULT 1,
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_dv_created DEFAULT SYSUTCDATETIME()
                )"
            );
            $disposalCols = [
                'stage' => "NVARCHAR(40) NOT NULL CONSTRAINT DF_disposals_stage DEFAULT 'planning'",
                'change_ticket' => 'NVARCHAR(100) NULL',
                'data_sensitivity' => 'NVARCHAR(30) NULL',
                'workload_migration' => 'NVARCHAR(MAX) NULL',
                'asset_verified' => 'BIT NOT NULL CONSTRAINT DF_disposals_asset_v DEFAULT 0',
                'planning_notes' => 'NVARCHAR(MAX) NULL',
                'planning_completed_at' => 'DATETIME2 NULL',
                'sanitize_category' => 'NVARCHAR(20) NULL',
                'sanitize_method' => 'NVARCHAR(100) NULL',
                'sanitize_on_site' => 'BIT NULL',
                'network_config_cleared' => 'BIT NOT NULL CONSTRAINT DF_disposals_net_clr DEFAULT 0',
                'credentials_cleared' => 'BIT NOT NULL CONSTRAINT DF_disposals_cred_clr DEFAULT 0',
                'logs_cleared' => 'BIT NOT NULL CONSTRAINT DF_disposals_logs_clr DEFAULT 0',
                'sanitize_details' => 'NVARCHAR(MAX) NULL',
                'sanitize_performed_by' => 'NVARCHAR(150) NULL',
                'sanitize_performed_at' => 'DATETIME2 NULL',
                'chain_of_custody' => 'NVARCHAR(150) NULL',
                'verification_notes' => 'NVARCHAR(MAX) NULL',
                'verified_by' => 'NVARCHAR(150) NULL',
                'verified_at' => 'DATETIME2 NULL',
                'vendor_id' => 'INT NULL',
                'disposition_ref' => 'NVARCHAR(100) NULL',
                'pickup_date' => 'DATE NULL',
                'lessons_learned' => 'NVARCHAR(MAX) NULL',
                'policy_updates' => 'NVARCHAR(MAX) NULL',
                'post_review_at' => 'DATETIME2 NULL',
                'post_review_by' => 'NVARCHAR(150) NULL',
            ];
            foreach ($disposalCols as $col => $def) {
                self::ensureColumn('disposals', $col, $def);
            }

            // Per-cabinet physical audit certifications
            self::ensureTable(
                'cabinet_audits',
                "CREATE TABLE cabinet_audits (
                    cabinet_audit_id INT IDENTITY(1,1) PRIMARY KEY,
                    cabinet_id INT NOT NULL,
                    audited_by INT NULL,
                    audited_by_name NVARCHAR(150) NULL,
                    certified BIT NOT NULL CONSTRAINT DF_cab_aud_cert DEFAULT 1,
                    comments NVARCHAR(MAX) NULL,
                    audited_at DATETIME2 NOT NULL CONSTRAINT DF_cab_aud_at DEFAULT SYSUTCDATETIME(),
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_cab_aud_created DEFAULT SYSUTCDATETIME()
                )"
            );
            // Per-cabinet audit cadence override (NULL = use site default)
            self::ensureColumn('cabinets', 'audit_interval_days', 'INT NULL');

            // RBAC: system roles + LDAP/Entra role maps
            self::ensureRoles();
            self::ensureTable(
                'role_group_maps',
                "CREATE TABLE role_group_maps (
                    map_id INT IDENTITY(1,1) PRIMARY KEY,
                    role_id INT NOT NULL,
                    auth_source NVARCHAR(20) NOT NULL,
                    group_id NVARCHAR(255) NOT NULL,
                    group_name NVARCHAR(255) NULL,
                    is_active BIT NOT NULL CONSTRAINT DF_rgm_active DEFAULT 1,
                    notes NVARCHAR(255) NULL,
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_rgm_created DEFAULT SYSUTCDATETIME()
                )"
            );
        } catch (Throwable $e) {
            App::log('Schema ensure failed: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Upsert platform roles (Viewer, Department Admin, Data Center Admin, Global Admin).
     * Legacy Administrator / Operator / Auditor kept and refreshed where present.
     */
    public static function ensureRoles(): void
    {
        if (!class_exists('AuthManager', false)) {
            // AuthManager may not be loaded yet during early boot
            $authPath = dirname(__DIR__) . '/src/Auth/AuthManager.php';
            if (is_file($authPath)) {
                require_once $authPath;
            }
        }
        if (!class_exists('AuthManager')) {
            return;
        }

        $defs = AuthManager::systemRoleDefinitions();
        // Also refresh legacy Operator as Data Center-equivalent if still present
        $defs['Operator'] = [
            'description' => 'Legacy role — prefer Data Center Admin (full inventory/power edits, no site settings)',
            'permissions' => $defs['Data Center Admin']['permissions'],
        ];
        $defs['Auditor'] = [
            'description' => 'Legacy role — prefer Viewer (read-only plus audits history)',
            'permissions' => array_values(array_unique(array_merge(
                $defs['Viewer']['permissions'],
                ['view_audits']
            ))),
        ];

        foreach ($defs as $name => $def) {
            $json = json_encode($def['permissions'], JSON_UNESCAPED_UNICODE);
            $existing = Database::fetchOne('SELECT role_id FROM roles WHERE name = ?', [$name]);
            if ($existing) {
                Database::update('roles', [
                    'description' => $def['description'],
                    'permissions' => $json,
                    'is_system' => 1,
                ], 'role_id = :id', [':id' => (int)$existing['role_id']]);
            } else {
                Database::insert('roles', [
                    'name' => $name,
                    'description' => $def['description'],
                    'permissions' => $json,
                    'is_system' => 1,
                ]);
            }
        }
    }

    private static function ensureColumn(string $table, string $column, string $definition): void
    {
        $exists = Database::fetchValue(
            "SELECT 1
             FROM sys.columns c
             INNER JOIN sys.tables t ON t.object_id = c.object_id
             WHERE t.name = ? AND SCHEMA_NAME(t.schema_id) = 'dbo' AND c.name = ?",
            [$table, $column]
        );
        if ($exists) {
            return;
        }
        $sql = "ALTER TABLE [{$table}] ADD [{$column}] {$definition}";
        Database::connection()->exec($sql);
    }

    private static function ensureTable(string $table, string $createSql): void
    {
        $exists = Database::fetchValue(
            "SELECT 1 FROM sys.tables WHERE name = ? AND SCHEMA_NAME(schema_id) = 'dbo'",
            [$table]
        );
        if ($exists) {
            return;
        }
        Database::connection()->exec($createSql);
    }
}
