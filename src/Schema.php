<?php
/**
 * ColdAisle - Lightweight schema upgrades for existing installs.
 *
 * Additive ensureColumn/ensureTable is cumulative: jumping many versions still
 * converges to latest desired shape without replaying intermediate releases.
 *
 * Ops visibility: Schema::status() / Settings → Schema health.
 */
declare(strict_types=1);

class Schema
{
    private static bool $ensured = false;

    /** Max ensure-run log lines kept on disk. */
    private const ENSURE_LOG_MAX = 40;

    /**
     * Run additive schema ensure.
     *
     * @param bool $force Re-run even when this app version already has a success stamp
     * @return array{ok:bool,skipped:bool,ms:float,error:?string,version:string}
     */
    public static function ensure(bool $force = false): array
    {
        $version = class_exists('App') ? App::VERSION : '0';
        $result = [
            'ok' => true,
            'skipped' => false,
            'ms' => 0.0,
            'error' => null,
            'version' => $version,
        ];

        if (!$force && self::$ensured) {
            $result['skipped'] = true;
            return $result;
        }
        self::$ensured = true;

        // Skip catalog chatter when this app version already ensured successfully.
        // (IIS FastCGI often uses a fresh PHP process per request — static alone is not enough.)
        $stampDir = App::ROOT . '/storage/tmp';
        $stamp = self::stampPath($version);
        if (!$force && is_file($stamp)) {
            $result['skipped'] = true;
            return $result;
        }

        $t0 = hrtime(true);
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
                // Dell iDRAC BMC address (IP or hostname) — preferred SNMP / web target when set
                'idrac_host' => 'NVARCHAR(255) NULL',
                // ICMP / ping monitoring (independent of SNMP)
                'icmp_monitor' => 'BIT NOT NULL CONSTRAINT DF_devices_icmp_mon DEFAULT 0',
                'icmp_fail_count' => 'INT NOT NULL CONSTRAINT DF_devices_icmp_fail DEFAULT 0',
                'icmp_last_at' => 'DATETIME2 NULL',
                'icmp_last_ok' => 'BIT NULL',
                'icmp_last_rtt_ms' => 'DECIMAL(10,2) NULL',
                'icmp_last_error' => 'NVARCHAR(255) NULL',
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

            // Unified alert routing (global / department / device / PDU)
            self::ensureTable(
                'alert_subscriptions',
                "CREATE TABLE alert_subscriptions (
                    subscription_id INT IDENTITY(1,1) PRIMARY KEY,
                    name NVARCHAR(150) NOT NULL,
                    scope NVARCHAR(20) NOT NULL CONSTRAINT DF_alert_sub_scope DEFAULT 'global',
                    department_id INT NULL,
                    device_id INT NULL,
                    pdu_id INT NULL,
                    categories NVARCHAR(200) NOT NULL CONSTRAINT DF_alert_sub_cats DEFAULT 'icmp,power,env,snmp,system',
                    min_severity NVARCHAR(20) NOT NULL CONSTRAINT DF_alert_sub_sev DEFAULT 'warning',
                    email_to NVARCHAR(500) NULL,
                    notify_in_app BIT NOT NULL CONSTRAINT DF_alert_sub_app DEFAULT 1,
                    notify_email BIT NOT NULL CONSTRAINT DF_alert_sub_mail DEFAULT 1,
                    is_active BIT NOT NULL CONSTRAINT DF_alert_sub_active DEFAULT 1,
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_alert_sub_created DEFAULT SYSUTCDATETIME(),
                    updated_at DATETIME2 NOT NULL CONSTRAINT DF_alert_sub_updated DEFAULT SYSUTCDATETIME()
                )"
            );
            // Inventory templates for rack/row PDUs (electrical + optional outlet layout)
            self::ensureTable(
                'pdu_templates',
                "CREATE TABLE pdu_templates (
                    template_id INT IDENTITY(1,1) PRIMARY KEY,
                    name NVARCHAR(150) NOT NULL,
                    vendor NVARCHAR(100) NULL,
                    model NVARCHAR(100) NULL,
                    fields_json NVARCHAR(MAX) NOT NULL CONSTRAINT DF_pdu_tpl_fields DEFAULT '{}',
                    outlets_json NVARCHAR(MAX) NULL,
                    notes NVARCHAR(500) NULL,
                    is_active BIT NOT NULL CONSTRAINT DF_pdu_tpl_active DEFAULT 1,
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_pdu_tpl_created DEFAULT SYSUTCDATETIME(),
                    updated_at DATETIME2 NOT NULL CONSTRAINT DF_pdu_tpl_updated DEFAULT SYSUTCDATETIME()
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
                'serial_no' => 'NVARCHAR(100) NULL',
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
                // Inventory template this PDU was created from / is linked to (bulk apply target)
                'pdu_template_id' => 'INT NULL',
                // Last multi-phase snapshot JSON: { "L1": {watts,amps,volts}, "L2":…, "L3":… }
                'last_poll_phases' => 'NVARCHAR(MAX) NULL',
                // Last per-outlet snapshot JSON: { "1": {amps,watts,name,state}, … }
                'last_poll_outlets' => 'NVARCHAR(MAX) NULL',
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
                // NIC MAC for ID labels / asset tags (manual; SNMP fill later)
                'mac_address' => 'NVARCHAR(64) NULL',
                // ICMP / ping monitoring (independent of SNMP)
                'icmp_monitor' => 'BIT NOT NULL CONSTRAINT DF_pdus_icmp_mon DEFAULT 0',
                'icmp_fail_count' => 'INT NOT NULL CONSTRAINT DF_pdus_icmp_fail DEFAULT 0',
                'icmp_last_at' => 'DATETIME2 NULL',
                'icmp_last_ok' => 'BIT NULL',
                'icmp_last_rtt_ms' => 'DECIMAL(10,2) NULL',
                'icmp_last_error' => 'NVARCHAR(255) NULL',
            ];
            foreach ($pduCols as $col => $def) {
                self::ensureColumn('pdus', $col, $def);
            }
            self::ensureColumn('pdu_outlets', 'rated_amps', 'DECIMAL(8,2) NULL');
            self::ensureColumn('pdu_outlets', 'device_power_supply_id', 'INT NULL');

            // Power history (Phase 1) — extend pdu_readings for charts / reports
            try {
                $hasReadings = Database::fetchValue(
                    "SELECT 1 FROM sys.tables WHERE name = 'pdu_readings' AND SCHEMA_NAME(schema_id) = 'dbo'"
                );
                if ($hasReadings) {
                    self::ensureColumn('pdu_readings', 'volts_ll', 'DECIMAL(8,2) NULL');
                    self::ensureColumn('pdu_readings', 'phases_json', 'NVARCHAR(MAX) NULL');
                    self::ensureColumn('pdu_readings', 'outage_phases', 'NVARCHAR(40) NULL');
                }
            } catch (Throwable $e) {
                // ignore
            }

            // Power alert cooldown / active state (after SNMP poll)
            self::ensureTable(
                'power_alert_state',
                "CREATE TABLE power_alert_state (
                    alert_key NVARCHAR(200) NOT NULL PRIMARY KEY,
                    pdu_id INT NOT NULL,
                    severity NVARCHAR(20) NOT NULL CONSTRAINT DF_pas_sev DEFAULT 'warning',
                    is_active BIT NOT NULL CONSTRAINT DF_pas_active DEFAULT 1,
                    last_fired_at DATETIME2 NULL,
                    last_cleared_at DATETIME2 NULL,
                    last_message NVARCHAR(500) NULL,
                    notify_count INT NOT NULL CONSTRAINT DF_pas_count DEFAULT 0
                )"
            );
            // Hold-window queue → one cascaded digest (PDU→cabinet→row→zone→DC)
            self::ensureTable(
                'power_alert_queue',
                "CREATE TABLE power_alert_queue (
                    queue_id BIGINT IDENTITY(1,1) PRIMARY KEY,
                    alert_key NVARCHAR(200) NOT NULL,
                    pdu_id INT NOT NULL,
                    severity NVARCHAR(20) NOT NULL CONSTRAINT DF_paq_sev DEFAULT 'warning',
                    kind NVARCHAR(40) NOT NULL CONSTRAINT DF_paq_kind DEFAULT 'power',
                    summary NVARCHAR(200) NULL,
                    message NVARCHAR(500) NULL,
                    queued_at DATETIME2 NOT NULL CONSTRAINT DF_paq_queued DEFAULT SYSUTCDATETIME(),
                    digest_id NVARCHAR(40) NULL,
                    digested_at DATETIME2 NULL
                )"
            );

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

            // Device templates: structured PSU defs (name/watts/connector) — PDU map is per-device
            self::ensureColumn('device_templates', 'power_supplies_json', 'NVARCHAR(MAX) NULL');

            // External import identity map (OpenDCIM, etc.) — re-run safe merges
            self::ensureTable(
                'import_id_map',
                "CREATE TABLE import_id_map (
                    map_id INT IDENTITY(1,1) PRIMARY KEY,
                    source NVARCHAR(40) NOT NULL,
                    entity_type NVARCHAR(40) NOT NULL,
                    source_id NVARCHAR(80) NOT NULL,
                    local_id INT NOT NULL,
                    updated_at DATETIME2 NOT NULL CONSTRAINT DF_import_id_map_upd DEFAULT SYSUTCDATETIME(),
                    CONSTRAINT UQ_import_id_map UNIQUE (source, entity_type, source_id)
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

            // Active session / presence registry (login + heartbeat)
            self::ensureTable(
                'auth_sessions',
                "CREATE TABLE auth_sessions (
                    session_id NVARCHAR(128) NOT NULL PRIMARY KEY,
                    user_id INT NOT NULL,
                    ip_address NVARCHAR(45) NULL,
                    user_agent NVARCHAR(500) NULL,
                    last_seen_at DATETIME2 NOT NULL CONSTRAINT DF_auth_sess_seen DEFAULT SYSUTCDATETIME(),
                    expires_at DATETIME2 NOT NULL,
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_auth_sess_created DEFAULT SYSUTCDATETIME()
                )"
            );
            self::ensureColumn(
                'auth_sessions',
                'last_seen_at',
                "DATETIME2 NOT NULL CONSTRAINT DF_auth_sess_seen2 DEFAULT SYSUTCDATETIME()"
            );

            // Cooling units (CRAC/CRAH/pumps/chillers) + environmental sensors
            self::ensureTable(
                'cooling_units',
                "CREATE TABLE cooling_units (
                    cooling_unit_id INT IDENTITY(1,1) PRIMARY KEY,
                    name NVARCHAR(150) NOT NULL,
                    unit_type NVARCHAR(40) NOT NULL CONSTRAINT DF_cu_type DEFAULT 'crac',
                    unit_role NVARCHAR(20) NOT NULL CONSTRAINT DF_cu_role DEFAULT 'primary',
                    standby_of_id INT NULL,
                    room_id INT NULL,
                    row_id INT NULL,
                    manufacturer NVARCHAR(100) NULL,
                    model NVARCHAR(100) NULL,
                    serial_no NVARCHAR(100) NULL,
                    asset_tag NVARCHAR(100) NULL,
                    primary_ip NVARCHAR(45) NULL,
                    hostname NVARCHAR(255) NULL,
                    warranty_provider NVARCHAR(150) NULL,
                    warranty_end DATE NULL,
                    install_date DATE NULL,
                    manufacture_date DATE NULL,
                    cooling_medium NVARCHAR(30) NOT NULL CONSTRAINT DF_cu_medium DEFAULT 'dx',
                    rated_kw_cooling DECIMAL(10,2) NULL,
                    rated_tons DECIMAL(10,2) NULL,
                    rated_cfm DECIMAL(12,2) NULL,
                    supply_temp_setpoint_c DECIMAL(6,2) NULL,
                    return_temp_setpoint_c DECIMAL(6,2) NULL,
                    ashrae_class NVARCHAR(20) NULL,
                    status NVARCHAR(30) NOT NULL CONSTRAINT DF_cu_status DEFAULT 'production',
                    pos_x DECIMAL(10,3) NULL,
                    pos_y DECIMAL(10,3) NULL,
                    pos_z DECIMAL(10,3) NULL,
                    rotation_deg DECIMAL(8,2) NULL,
                    front_facing NVARCHAR(10) NULL,
                    width_mm INT NULL,
                    depth_mm INT NULL,
                    height_mm INT NULL,
                    color_hex NVARCHAR(7) NULL,
                    snmp_enabled BIT NOT NULL CONSTRAINT DF_cu_snmp_en DEFAULT 0,
                    snmp_version NVARCHAR(10) NULL,
                    snmp_community NVARCHAR(100) NULL,
                    snmp_port INT NULL,
                    snmp_v3_profile_id INT NULL,
                    snmp_v3_sec_level NVARCHAR(30) NULL,
                    snmp_security_name NVARCHAR(100) NULL,
                    snmp_auth_protocol NVARCHAR(20) NULL,
                    snmp_auth_passphrase NVARCHAR(255) NULL,
                    snmp_priv_protocol NVARCHAR(20) NULL,
                    snmp_priv_passphrase NVARCHAR(255) NULL,
                    snmp_context NVARCHAR(100) NULL,
                    snmp_site_template_id INT NULL,
                    snmp_auto_poll BIT NOT NULL CONSTRAINT DF_cu_snmp_auto DEFAULT 0,
                    snmp_last_poll_at DATETIME2 NULL,
                    last_poll_json NVARCHAR(MAX) NULL,
                    notes NVARCHAR(MAX) NULL,
                    is_active BIT NOT NULL CONSTRAINT DF_cu_active DEFAULT 1,
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_cu_created DEFAULT SYSUTCDATETIME(),
                    updated_at DATETIME2 NOT NULL CONSTRAINT DF_cu_updated DEFAULT SYSUTCDATETIME()
                )"
            );
            // Additive SNMPv3 credential columns (installs created before full v3 form)
            self::ensureColumn('cooling_units', 'snmp_v3_sec_level', 'NVARCHAR(30) NULL');
            self::ensureColumn('cooling_units', 'snmp_security_name', 'NVARCHAR(100) NULL');
            self::ensureColumn('cooling_units', 'snmp_auth_protocol', 'NVARCHAR(20) NULL');
            self::ensureColumn('cooling_units', 'snmp_auth_passphrase', 'NVARCHAR(255) NULL');
            self::ensureColumn('cooling_units', 'snmp_priv_protocol', 'NVARCHAR(20) NULL');
            self::ensureColumn('cooling_units', 'snmp_priv_passphrase', 'NVARCHAR(255) NULL');
            self::ensureColumn('cooling_units', 'snmp_context', 'NVARCHAR(100) NULL');
            self::ensureTable(
                'env_sensors',
                "CREATE TABLE env_sensors (
                    sensor_id INT IDENTITY(1,1) PRIMARY KEY,
                    name NVARCHAR(150) NOT NULL,
                    sensor_kind NVARCHAR(40) NOT NULL CONSTRAINT DF_es_kind DEFAULT 'temperature',
                    host_type NVARCHAR(30) NOT NULL CONSTRAINT DF_es_host DEFAULT 'standalone',
                    cooling_unit_id INT NULL,
                    pdu_id INT NULL,
                    device_id INT NULL,
                    cabinet_id INT NULL,
                    room_id INT NULL,
                    location_label NVARCHAR(150) NULL,
                    placement NVARCHAR(40) NULL,
                    unit NVARCHAR(20) NULL,
                    ashrae_metric NVARCHAR(40) NULL,
                    warn_low DECIMAL(12,4) NULL,
                    warn_high DECIMAL(12,4) NULL,
                    crit_low DECIMAL(12,4) NULL,
                    crit_high DECIMAL(12,4) NULL,
                    snmp_oid NVARCHAR(255) NULL,
                    snmp_index NVARCHAR(80) NULL,
                    snmp_site_template_id INT NULL,
                    last_value DECIMAL(14,4) NULL,
                    last_seen_at DATETIME2 NULL,
                    pos_x DECIMAL(10,3) NULL,
                    pos_y DECIMAL(10,3) NULL,
                    pos_z DECIMAL(10,3) NULL,
                    notes NVARCHAR(500) NULL,
                    is_active BIT NOT NULL CONSTRAINT DF_es_active DEFAULT 1,
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_es_created DEFAULT SYSUTCDATETIME(),
                    updated_at DATETIME2 NOT NULL CONSTRAINT DF_es_updated DEFAULT SYSUTCDATETIME()
                )"
            );
            // Additive columns if table predated placement / multi-probe index
            self::ensureColumn('env_sensors', 'snmp_index', 'NVARCHAR(80) NULL');
            self::ensureColumn('env_sensors', 'pos_x', 'DECIMAL(10,3) NULL');
            self::ensureColumn('env_sensors', 'pos_y', 'DECIMAL(10,3) NULL');
            self::ensureColumn('env_sensors', 'pos_z', 'DECIMAL(10,3) NULL');
            // Combo temp+humidity sensors: secondary last reading from SNMP poll
            self::ensureColumn('env_sensors', 'last_humidity', 'DECIMAL(14,4) NULL');
            self::ensureColumn('env_sensors', 'last_alert_level', 'NVARCHAR(20) NULL');
            self::ensureColumn('env_sensors', 'last_alert_at', 'DATETIME2 NULL');
            self::ensureTable(
                'env_readings',
                "CREATE TABLE env_readings (
                    reading_id BIGINT IDENTITY(1,1) PRIMARY KEY,
                    sensor_id INT NOT NULL,
                    value DECIMAL(14,4) NOT NULL,
                    recorded_at DATETIME2 NOT NULL CONSTRAINT DF_er_at DEFAULT SYSUTCDATETIME()
                )"
            );
            // Distinguish temp vs humidity series for combo probes
            self::ensureColumn('env_readings', 'metric', 'NVARCHAR(40) NULL');

            // Rare idempotent reshapes / backfills (not only ADD column)
            self::runIdempotentReshapes();

            // Mark this app version as schema-ready (skips catalog probes on next request)
            if (!is_dir($stampDir)) {
                @mkdir($stampDir, 0775, true);
            }
            $ms = (hrtime(true) - $t0) / 1e6;
            $stampBody = json_encode([
                'version' => $version,
                'ok' => true,
                'at' => gmdate('c'),
                'ms' => round($ms, 1),
            ], JSON_UNESCAPED_SLASHES);
            @file_put_contents($stamp, ($stampBody ?: date('c')) . "\n");
            self::recordEnsureRun(true, $ms, null, $force);
            $result['ms'] = round($ms, 1);
            return $result;
        } catch (Throwable $e) {
            $ms = (hrtime(true) - $t0) / 1e6;
            App::log('Schema ensure failed: ' . $e->getMessage(), 'error');
            self::recordEnsureRun(false, $ms, $e->getMessage(), $force);
            // Allow retry on next request
            self::$ensured = false;
            if (is_file($stamp)) {
                @unlink($stamp);
            }
            $result['ok'] = false;
            $result['ms'] = round($ms, 1);
            $result['error'] = $e->getMessage();
            return $result;
        }
    }

    public static function stampPath(?string $version = null): string
    {
        $version = $version ?? (class_exists('App') ? App::VERSION : '0');
        $safe = preg_replace('/[^0-9A-Za-z._-]/', '_', $version) ?? '0';
        return App::ROOT . '/storage/tmp/schema_ok_' . $safe . '.flag';
    }

    /**
     * Clear success stamp so the next ensure() re-probes the catalog.
     */
    public static function clearStamp(?string $version = null): void
    {
        $p = self::stampPath($version);
        if (is_file($p)) {
            @unlink($p);
        }
        self::$ensured = false;
    }

    /**
     * Expected tables/columns managed by ensure() plus core install tables.
     * Used by Settings → Schema health (not a full sql/schema.sql dump).
     *
     * @return array{core_tables:list<string>,tables:array<string,list<string>>}
     */
    public static function expectedInventory(): array
    {
        $core = [
            'settings', 'roles', 'users', 'auth_sessions', 'audit_log',
            'departments', 'sites', 'datacenters', 'rooms', 'cabinet_rows', 'cabinets',
            'manufacturers', 'device_templates', 'devices', 'device_ports',
            'power_zones', 'pdus', 'pdu_outlets', 'notifications',
        ];
        $managed = [
            'datacenters' => ['north_edge'],
            'cabinet_rows' => ['zone_id', 'color_hex'],
            'departments' => ['color_hex'],
            'department_group_maps' => ['map_id', 'department_id', 'auth_source', 'group_id'],
            'devices' => [
                'parent_device_id', 'manufacture_date', 'weight_kg', 'num_data_ports', 'num_power_ports',
                'warranty_provider', 'tags', 'snmp_version', 'snmp_community', 'snmp_fail_count',
                'snmp_v3_profile_id', 'snmp_site_template_id', 'snmp_auto_poll',
                'snmp_last_poll_at', 'snmp_last_poll_watts', 'snmp_last_poll_amps',
                'idrac_host', 'icmp_monitor', 'icmp_fail_count', 'icmp_last_at', 'icmp_last_ok',
            ],
            'snmp_site_oid_templates' => ['template_id', 'name', 'oid_map'],
            'pdu_templates' => ['template_id', 'name', 'fields_json'],
            'snmp_v3_profiles' => ['profile_id', 'name', 'security_name'],
            'alert_subscriptions' => ['subscription_id', 'name', 'scope', 'categories'],
            'device_notes' => ['note_id', 'device_id', 'note_text'],
            'pdus' => [
                'mount_style', 'position_u', 'u_height', 'snmp_community', 'phases',
                'output_mode', 'snmp_site_template_id', 'snmp_auto_poll', 'pdu_template_id',
                'last_poll_phases', 'room_id', 'pos_x', 'pos_y',
                'icmp_monitor', 'icmp_fail_count', 'icmp_last_at', 'icmp_last_ok',
            ],
            'pdu_outlets' => ['rated_amps', 'device_power_supply_id'],
            'power_alert_state' => ['alert_key', 'pdu_id', 'severity'],
            'power_alert_queue' => ['queue_id', 'alert_key', 'pdu_id'],
            'pdu_breakers' => ['breaker_id', 'pdu_id', 'slots_json'],
            'device_power_supplies' => ['power_supply_id', 'device_id', 'name'],
            'device_templates' => ['power_supplies_json'],
            'import_id_map' => ['map_id', 'source', 'entity_type', 'source_id', 'local_id'],
            'disposal_vendors' => ['vendor_id', 'name'],
            'disposals' => ['stage', 'vendor_id', 'change_ticket'],
            'cabinet_audits' => ['cabinet_audit_id', 'cabinet_id', 'audited_at'],
            'cabinets' => ['audit_interval_days'],
            'role_group_maps' => ['map_id', 'role_id', 'auth_source', 'group_id'],
            'auth_sessions' => ['session_id', 'user_id', 'last_seen_at', 'expires_at'],
            'cooling_units' => [
                'cooling_unit_id', 'name', 'unit_type', 'unit_role', 'cooling_medium',
                'room_id', 'pos_x', 'pos_y', 'snmp_enabled', 'primary_ip',
            ],
            'env_sensors' => [
                'sensor_id', 'name', 'sensor_kind', 'host_type', 'room_id',
                'cooling_unit_id', 'pdu_id', 'device_id', 'last_value',
                'snmp_oid', 'snmp_index', 'pos_x', 'pos_y', 'pos_z',
            ],
            'env_readings' => ['reading_id', 'sensor_id', 'value', 'recorded_at'],
        ];
        $core = array_values(array_unique(array_merge($core, [
            'cooling_units', 'env_sensors', 'env_readings',
        ])));
        return ['core_tables' => $core, 'tables' => $managed];
    }

    /**
     * Compare expected inventory to live SQL Server catalog.
     *
     * @return array{
     *   app_version:string,
     *   ok:bool,
     *   stamp:array{exists:bool,path:string,at:?string,version:?string,ms:?float},
     *   last_ensure:?array,
     *   ensure_log:list<array>,
     *   missing_tables:list<string>,
     *   missing_columns:list<array{table:string,column:string}>,
     *   present_tables:int,
     *   checked_tables:int,
     *   checked_columns:int,
     *   live_table_count:int
     * }
     */
    public static function status(): array
    {
        $inv = self::expectedInventory();
        $version = class_exists('App') ? App::VERSION : '0';
        $stampPath = self::stampPath($version);
        $stampMeta = self::readStampMeta($stampPath);

        $liveTables = [];
        $liveColumns = []; // table => set of column names
        try {
            $trows = Database::fetchAll(
                "SELECT t.name AS table_name
                 FROM sys.tables t
                 WHERE SCHEMA_NAME(t.schema_id) = 'dbo'
                 ORDER BY t.name"
            );
            foreach ($trows as $r) {
                $liveTables[strtolower((string)$r['table_name'])] = (string)$r['table_name'];
            }
            $crows = Database::fetchAll(
                "SELECT t.name AS table_name, c.name AS column_name
                 FROM sys.columns c
                 INNER JOIN sys.tables t ON t.object_id = c.object_id
                 WHERE SCHEMA_NAME(t.schema_id) = 'dbo'"
            );
            foreach ($crows as $r) {
                $tn = strtolower((string)$r['table_name']);
                $liveColumns[$tn][(string)$r['column_name']] = true;
            }
        } catch (Throwable $e) {
            return [
                'app_version' => $version,
                'ok' => false,
                'stamp' => $stampMeta,
                'last_ensure' => self::lastEnsureFromSettings(),
                'ensure_log' => self::readEnsureLog(10),
                'missing_tables' => ['(could not read catalog: ' . $e->getMessage() . ')'],
                'missing_columns' => [],
                'present_tables' => 0,
                'checked_tables' => 0,
                'checked_columns' => 0,
                'live_table_count' => 0,
                'error' => $e->getMessage(),
            ];
        }

        $missingTables = [];
        $missingColumns = [];
        $checkedTables = 0;
        $checkedColumns = 0;
        $present = 0;

        $allTables = array_values(array_unique(array_merge(
            $inv['core_tables'],
            array_keys($inv['tables'])
        )));
        sort($allTables);

        foreach ($allTables as $table) {
            $checkedTables++;
            $key = strtolower($table);
            if (!isset($liveTables[$key])) {
                $missingTables[] = $table;
                continue;
            }
            $present++;
            $cols = $inv['tables'][$table] ?? [];
            foreach ($cols as $col) {
                $checkedColumns++;
                if (empty($liveColumns[$key][$col])) {
                    $missingColumns[] = ['table' => $table, 'column' => $col];
                }
            }
        }

        $ok = $missingTables === [] && $missingColumns === [];

        return [
            'app_version' => $version,
            'ok' => $ok,
            'stamp' => $stampMeta,
            'last_ensure' => self::lastEnsureFromSettings(),
            'ensure_log' => self::readEnsureLog(12),
            'missing_tables' => $missingTables,
            'missing_columns' => $missingColumns,
            'present_tables' => $present,
            'checked_tables' => $checkedTables,
            'checked_columns' => $checkedColumns,
            'live_table_count' => count($liveTables),
        ];
    }

    /**
     * Idempotent fixes that are not pure ADD column (backfills, soft constraints).
     */
    private static function runIdempotentReshapes(): void
    {
        // Presence: if last_seen_at somehow null on legacy rows, copy created_at
        try {
            Database::query(
                "UPDATE auth_sessions
                 SET last_seen_at = created_at
                 WHERE last_seen_at IS NULL AND created_at IS NOT NULL"
            );
        } catch (Throwable $e) {
            // table/column may not exist yet mid-ensure
        }

        // PDU breakers: empty slots_json → [] so JSON consumers never see NULL
        try {
            Database::query(
                "UPDATE pdu_breakers
                 SET slots_json = '[]'
                 WHERE slots_json IS NULL OR LTRIM(RTRIM(slots_json)) = ''"
            );
        } catch (Throwable $e) {
            // ignore
        }
    }

    private static function recordEnsureRun(bool $ok, float $ms, ?string $error, bool $force): void
    {
        $entry = [
            'at' => gmdate('c'),
            'version' => class_exists('App') ? App::VERSION : '0',
            'ok' => $ok,
            'ms' => round($ms, 1),
            'force' => $force,
            'error' => $error,
        ];
        try {
            if (class_exists('SettingsService', false)) {
                SettingsService::set('schema_last_ensure_json', json_encode($entry, JSON_UNESCAPED_SLASHES) ?: '{}', 'schema');
                SettingsService::set('schema_version', (string)$entry['version'], 'schema');
            }
        } catch (Throwable $e) {
            // ignore
        }
        $dir = App::ROOT . '/storage/tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $logFile = $dir . '/schema_ensure_log.jsonl';
        @file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        // Trim log
        try {
            $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            if (count($lines) > self::ENSURE_LOG_MAX) {
                $lines = array_slice($lines, -self::ENSURE_LOG_MAX);
                @file_put_contents($logFile, implode("\n", $lines) . "\n", LOCK_EX);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    /** @return list<array<string,mixed>> */
    private static function readEnsureLog(int $limit = 12): array
    {
        $logFile = App::ROOT . '/storage/tmp/schema_ensure_log.jsonl';
        if (!is_file($logFile)) {
            return [];
        }
        $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, -$limit);
        $out = [];
        foreach (array_reverse($lines) as $line) {
            $j = json_decode($line, true);
            if (is_array($j)) {
                $out[] = $j;
            }
        }
        return $out;
    }

    private static function lastEnsureFromSettings(): ?array
    {
        try {
            if (!class_exists('SettingsService', false)) {
                return null;
            }
            $raw = SettingsService::get('schema_last_ensure_json', '');
            if ($raw === '' || $raw === null) {
                return null;
            }
            $j = json_decode((string)$raw, true);
            return is_array($j) ? $j : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @return array{exists:bool,path:string,at:?string,version:?string,ms:?float} */
    private static function readStampMeta(string $path): array
    {
        $meta = [
            'exists' => is_file($path),
            'path' => $path,
            'at' => null,
            'version' => null,
            'ms' => null,
        ];
        if (!is_file($path)) {
            return $meta;
        }
        $raw = trim((string)@file_get_contents($path));
        if ($raw === '') {
            $meta['at'] = gmdate('c', (int)@filemtime($path));
            return $meta;
        }
        $j = json_decode($raw, true);
        if (is_array($j)) {
            $meta['at'] = isset($j['at']) ? (string)$j['at'] : null;
            $meta['version'] = isset($j['version']) ? (string)$j['version'] : null;
            $meta['ms'] = isset($j['ms']) ? (float)$j['ms'] : null;
        } else {
            $meta['at'] = $raw;
        }
        return $meta;
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
