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
                'snmp_engine_id' => 'NVARCHAR(80) NULL',
                // Site OID template (discovered or manual) — OIDs stored once, not per device
                'snmp_site_template_id' => 'INT NULL',
                'snmp_auto_poll' => 'BIT NOT NULL CONSTRAINT DF_devices_snmp_auto DEFAULT 0',
                'snmp_last_poll_at' => 'DATETIME2 NULL',
                'snmp_last_poll_watts' => 'DECIMAL(18,4) NULL',
                'snmp_last_poll_amps' => 'DECIMAL(18,4) NULL',
                'last_poll_json' => 'NVARCHAR(MAX) NULL',
                // Dell iDRAC BMC address (IP or hostname) — preferred SNMP / web target when set
                'idrac_host' => 'NVARCHAR(255) NULL',
                // ICMP / ping monitoring (independent of SNMP)
                'icmp_monitor' => 'BIT NOT NULL CONSTRAINT DF_devices_icmp_mon DEFAULT 0',
                'icmp_fail_count' => 'INT NOT NULL CONSTRAINT DF_devices_icmp_fail DEFAULT 0',
                'icmp_last_at' => 'DATETIME2 NULL',
                'icmp_last_ok' => 'BIT NULL',
                'icmp_last_rtt_ms' => 'DECIMAL(10,2) NULL',
                'icmp_last_error' => 'NVARCHAR(255) NULL',
                // Asset lifecycle (G-B3): PO / purchase / RMA + warranty digest tracking
                'po_number' => 'NVARCHAR(100) NULL',
                'purchase_date' => 'DATE NULL',
                'purchase_cost' => 'DECIMAL(14,2) NULL',
                'purchase_vendor' => 'NVARCHAR(150) NULL',
                'rma_number' => 'NVARCHAR(100) NULL',
                'rma_status' => 'NVARCHAR(30) NULL',
                'rma_notes' => 'NVARCHAR(MAX) NULL',
                'warranty_notify_for_end' => 'DATE NULL',
            ];
            foreach ($deviceCols as $col => $def) {
                self::ensureColumn('devices', $col, $def);
            }

            // Chain-of-custody / lifecycle event log (G-B3)
            self::ensureTable(
                'asset_events',
                "CREATE TABLE asset_events (
                    event_id INT IDENTITY(1,1) PRIMARY KEY,
                    device_id INT NOT NULL,
                    event_type NVARCHAR(40) NOT NULL,
                    summary NVARCHAR(500) NOT NULL,
                    from_value NVARCHAR(255) NULL,
                    to_value NVARCHAR(255) NULL,
                    notes NVARCHAR(MAX) NULL,
                    meta_json NVARCHAR(MAX) NULL,
                    performed_by INT NULL,
                    performed_by_name NVARCHAR(150) NULL,
                    occurred_at DATETIME2 NOT NULL CONSTRAINT DF_ae_at DEFAULT SYSUTCDATETIME(),
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_ae_created DEFAULT SYSUTCDATETIME()
                )"
            );

            self::ensureTable(
                'device_snmp_readings',
                "CREATE TABLE device_snmp_readings (
                    reading_id BIGINT IDENTITY(1,1) PRIMARY KEY,
                    device_id INT NOT NULL,
                    metric_name NVARCHAR(100) NOT NULL,
                    metric_value DECIMAL(18,6) NULL,
                    metric_text NVARCHAR(255) NULL,
                    polled_at DATETIME2 NOT NULL CONSTRAINT DF_dsr_at DEFAULT SYSUTCDATETIME()
                )"
            );
            try {
                $hasDsr = Database::fetchValue(
                    "SELECT 1 FROM sys.tables WHERE name = 'device_snmp_readings' AND SCHEMA_NAME(schema_id) = 'dbo'"
                );
                if ($hasDsr) {
                    $idx = Database::fetchValue(
                        "SELECT 1 FROM sys.indexes WHERE name = 'IX_dsr_device_metric_time' AND object_id = OBJECT_ID('dbo.device_snmp_readings')"
                    );
                    if (!$idx) {
                        Database::query(
                            'CREATE NONCLUSTERED INDEX IX_dsr_device_metric_time ON device_snmp_readings(device_id, metric_name, polled_at DESC)'
                        );
                    }
                }
            } catch (Throwable $e) {
                // index is optional
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
            // Custom SNMP metric thresholds (Settings → Alerts)
            self::ensureTable(
                'snmp_thresholds',
                "CREATE TABLE snmp_thresholds (
                    threshold_id INT IDENTITY(1,1) PRIMARY KEY,
                    name NVARCHAR(150) NOT NULL,
                    entity_type NVARCHAR(20) NOT NULL CONSTRAINT DF_snmp_thr_etype DEFAULT 'device',
                    entity_id INT NULL,
                    metric_key NVARCHAR(100) NOT NULL,
                    oid NVARCHAR(255) NULL,
                    warn_low DECIMAL(18,6) NULL,
                    warn_high DECIMAL(18,6) NULL,
                    crit_low DECIMAL(18,6) NULL,
                    crit_high DECIMAL(18,6) NULL,
                    unit NVARCHAR(20) NULL,
                    scale_divisor DECIMAL(18,6) NOT NULL CONSTRAINT DF_snmp_thr_scale DEFAULT 1,
                    cooldown_min INT NOT NULL CONSTRAINT DF_snmp_thr_cd DEFAULT 60,
                    is_active BIT NOT NULL CONSTRAINT DF_snmp_thr_active DEFAULT 1,
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_snmp_thr_created DEFAULT SYSUTCDATETIME(),
                    updated_at DATETIME2 NOT NULL CONSTRAINT DF_snmp_thr_updated DEFAULT SYSUTCDATETIME()
                )"
            );
            self::ensureTable(
                'snmp_threshold_state',
                "CREATE TABLE snmp_threshold_state (
                    threshold_id INT NOT NULL,
                    entity_type NVARCHAR(20) NOT NULL,
                    entity_id INT NOT NULL,
                    last_alert_level NVARCHAR(20) NULL,
                    last_alert_at DATETIME2 NULL,
                    last_value DECIMAL(18,6) NULL,
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_snmp_thr_st_created DEFAULT SYSUTCDATETIME(),
                    updated_at DATETIME2 NOT NULL CONSTRAINT DF_snmp_thr_st_updated DEFAULT SYSUTCDATETIME(),
                    CONSTRAINT PK_snmp_threshold_state PRIMARY KEY (threshold_id, entity_type, entity_id)
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
            // Inventory templates for UPS (Symmetra / Smart-UPS class)
            self::ensureTable(
                'ups_templates',
                "CREATE TABLE ups_templates (
                    template_id INT IDENTITY(1,1) PRIMARY KEY,
                    name NVARCHAR(150) NOT NULL,
                    vendor NVARCHAR(100) NULL,
                    model NVARCHAR(100) NULL,
                    fields_json NVARCHAR(MAX) NOT NULL CONSTRAINT DF_ups_tpl_fields DEFAULT '{}',
                    notes NVARCHAR(500) NULL,
                    is_active BIT NOT NULL CONSTRAINT DF_ups_tpl_active DEFAULT 1,
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_ups_tpl_created DEFAULT SYSUTCDATETIME(),
                    updated_at DATETIME2 NOT NULL CONSTRAINT DF_ups_tpl_updated DEFAULT SYSUTCDATETIME()
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
                'snmp_engine_id' => 'NVARCHAR(80) NULL',
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
                // Facility / site load rollup (avoid double-counting rack under row PDUs)
                'include_in_site_load' => 'BIT NOT NULL CONSTRAINT DF_pdus_site_load DEFAULT 1',
                'po_number' => 'NVARCHAR(100) NULL',
                'purchase_date' => 'DATE NULL',
                'purchase_cost' => 'DECIMAL(14,2) NULL',
                'purchase_vendor' => 'NVARCHAR(150) NULL',
                'warranty_provider' => 'NVARCHAR(150) NULL',
                'warranty_end' => 'DATE NULL',
                'install_date' => 'DATE NULL',
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
            // Due-soon mail flags (G-B5)
            self::ensureColumn('disposals', 'notification_sent', 'BIT NOT NULL CONSTRAINT DF_disposals_notif DEFAULT 0');
            self::ensureColumn('disposals', 'notification_sent_at', 'DATETIME2 NULL');

            // Structured cable plant / raceways (G-B1)
            self::ensureTable(
                'cable_paths',
                "CREATE TABLE cable_paths (
                    path_id INT IDENTITY(1,1) PRIMARY KEY,
                    room_id INT NULL,
                    name NVARCHAR(100) NOT NULL,
                    path_type NVARCHAR(30) NOT NULL CONSTRAINT DF_cp_type DEFAULT 'overhead',
                    waypoints NVARCHAR(MAX) NULL,
                    color_hex NVARCHAR(7) NOT NULL CONSTRAINT DF_cp_color DEFAULT '#38bdf8',
                    notes NVARCHAR(MAX) NULL
                )"
            );
            self::ensureColumn('cable_paths', 'media_class', "NVARCHAR(20) NOT NULL CONSTRAINT DF_cp_media DEFAULT 'mixed'");
            self::ensureColumn('cable_paths', 'path_kind', "NVARCHAR(40) NOT NULL CONSTRAINT DF_cp_kind DEFAULT 'ladder'");
            self::ensureColumn('cable_paths', 'feed_to', "NVARCHAR(20) NOT NULL CONSTRAINT DF_cp_feed DEFAULT 'overhead'");
            self::ensureColumn('cable_paths', 'width_m', 'DECIMAL(8,3) NULL');
            self::ensureColumn('cable_paths', 'elevation_m', 'DECIMAL(8,3) NULL'); // path height AFF for 3D (m)
            self::ensureColumn('cable_paths', 'is_active', 'BIT NOT NULL CONSTRAINT DF_cp_active DEFAULT 1');
            self::ensureColumn('cable_paths', 'path_code', 'NVARCHAR(40) NULL');
            self::ensureColumn('cable_paths', 'segment_class', 'NVARCHAR(20) NULL');
            self::ensureTable(
                'cables',
                "CREATE TABLE cables (
                    cable_id INT IDENTITY(1,1) PRIMARY KEY,
                    cable_label NVARCHAR(100) NULL,
                    media_type NVARCHAR(50) NULL,
                    length_m DECIMAL(8,2) NULL,
                    color NVARCHAR(30) NULL,
                    a_port_id INT NULL,
                    b_port_id INT NULL,
                    path_id INT NULL,
                    status NVARCHAR(30) NOT NULL CONSTRAINT DF_cables_status DEFAULT 'active',
                    notes NVARCHAR(MAX) NULL,
                    installed_at DATETIME2 NULL,
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_cables_created DEFAULT SYSUTCDATETIME()
                )"
            );
            self::ensureColumn('cables', 'circuit_id', 'NVARCHAR(100) NULL');
            self::ensureColumn('cables', 'speed', 'NVARCHAR(30) NULL');
            self::ensureColumn('cables', 'color_hex', 'NVARCHAR(7) NULL');
            self::ensureColumn('cables', 'cable_role', "NVARCHAR(30) NOT NULL CONSTRAINT DF_cables_role DEFAULT 'patch'");
            self::ensureColumn('cables', 'strand_count', 'INT NULL');
            // Multi-hop raceway route: {"path_ids":[1,5,3],"source":"manual|calculated"}
            self::ensureColumn('cables', 'path_route_json', 'NVARCHAR(MAX) NULL');

            // Notification active vs cleared (history kept; UI shows green check when recovered)
            self::ensureColumn('notifications', 'is_cleared', 'BIT NOT NULL CONSTRAINT DF_notif_cleared DEFAULT 0');
            self::ensureColumn('notifications', 'cleared_at', 'DATETIME2 NULL');

            self::ensureColumn('users', 'is_service_account', 'BIT NOT NULL CONSTRAINT DF_users_svc DEFAULT 0');
            self::ensureColumn('users', 'can_login', 'BIT NOT NULL CONSTRAINT DF_users_canlogin DEFAULT 1');
            self::ensureTable(
                'api_tokens',
                "CREATE TABLE api_tokens (
                    token_id INT IDENTITY(1,1) PRIMARY KEY,
                    user_id INT NOT NULL,
                    name NVARCHAR(100) NOT NULL,
                    token_prefix NVARCHAR(20) NOT NULL,
                    token_hash NVARCHAR(64) NOT NULL,
                    scopes NVARCHAR(40) NOT NULL CONSTRAINT DF_api_tok_scopes DEFAULT 'read',
                    created_by INT NULL,
                    last_used_at DATETIME2 NULL,
                    expires_at DATETIME2 NULL,
                    revoked_at DATETIME2 NULL,
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_api_tok_created DEFAULT SYSUTCDATETIME()
                )"
            );

            // Password reset tokens (G-B5 forgot-password)
            self::ensureTable(
                'password_reset_tokens',
                "CREATE TABLE password_reset_tokens (
                    token_id INT IDENTITY(1,1) PRIMARY KEY,
                    user_id INT NOT NULL,
                    token_hash NVARCHAR(64) NOT NULL,
                    email NVARCHAR(255) NULL,
                    expires_at DATETIME2 NOT NULL,
                    used_at DATETIME2 NULL,
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_prt_created DEFAULT SYSUTCDATETIME()
                )"
            );

            // Change / move work orders (G-B2)
            self::ensureTable(
                'work_orders',
                "CREATE TABLE work_orders (
                    work_order_id INT IDENTITY(1,1) PRIMARY KEY,
                    title NVARCHAR(200) NOT NULL,
                    work_type NVARCHAR(30) NOT NULL CONSTRAINT DF_wo_type DEFAULT 'move',
                    status NVARCHAR(30) NOT NULL CONSTRAINT DF_wo_status DEFAULT 'draft',
                    change_ticket NVARCHAR(100) NULL,
                    requested_by INT NULL,
                    assigned_to INT NULL,
                    scheduled_date DATE NULL,
                    completed_at DATETIME2 NULL,
                    notes NVARCHAR(MAX) NULL,
                    checklist_json NVARCHAR(MAX) NULL,
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_wo_created DEFAULT SYSUTCDATETIME(),
                    updated_at DATETIME2 NOT NULL CONSTRAINT DF_wo_updated DEFAULT SYSUTCDATETIME()
                )"
            );
            self::ensureTable(
                'work_order_items',
                "CREATE TABLE work_order_items (
                    item_id INT IDENTITY(1,1) PRIMARY KEY,
                    work_order_id INT NOT NULL,
                    device_id INT NOT NULL,
                    from_cabinet_id INT NULL,
                    from_position_u INT NULL,
                    to_cabinet_id INT NULL,
                    to_position_u INT NULL,
                    item_status NVARCHAR(20) NOT NULL CONSTRAINT DF_woi_status DEFAULT 'pending',
                    notes NVARCHAR(500) NULL,
                    sort_order INT NOT NULL CONSTRAINT DF_woi_sort DEFAULT 0,
                    completed_at DATETIME2 NULL,
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_woi_created DEFAULT SYSUTCDATETIME()
                )"
            );
            // ManageEngine ServiceDesk Plus Cloud link (ITSM)
            self::ensureColumn('work_orders', 'itsm_provider', 'NVARCHAR(30) NULL');
            self::ensureColumn('work_orders', 'itsm_request_id', 'NVARCHAR(40) NULL');
            self::ensureColumn('work_orders', 'itsm_display_id', 'NVARCHAR(40) NULL');
            self::ensureColumn('work_orders', 'itsm_url', 'NVARCHAR(500) NULL');
            self::ensureColumn('work_orders', 'itsm_last_sync_at', 'DATETIME2 NULL');
            self::ensureColumn('work_orders', 'itsm_last_error', 'NVARCHAR(500) NULL');
            try {
                $idx = Database::fetchValue(
                    "SELECT 1 FROM sys.indexes WHERE name = 'IX_work_orders_itsm_request' AND object_id = OBJECT_ID('dbo.work_orders')"
                );
                if (!$idx) {
                    Database::query(
                        'CREATE NONCLUSTERED INDEX IX_work_orders_itsm_request ON work_orders(itsm_request_id)'
                    );
                }
            } catch (Throwable $e) {
                // index is optional
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
            self::ensureColumn('cabinet_audits', 'snapshot_json', 'NVARCHAR(MAX) NULL');
            self::ensureTable(
                'cabinet_audit_photos',
                "CREATE TABLE cabinet_audit_photos (
                    photo_id INT IDENTITY(1,1) PRIMARY KEY,
                    cabinet_audit_id INT NOT NULL,
                    cabinet_id INT NOT NULL,
                    position_u INT NULL,
                    face NVARCHAR(10) NULL,
                    rel_path NVARCHAR(400) NOT NULL,
                    caption NVARCHAR(200) NULL,
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_cab_aud_photo_at DEFAULT SYSUTCDATETIME()
                )"
            );

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
            self::ensureColumn('cooling_units', 'snmp_engine_id', 'NVARCHAR(80) NULL');

            // UPS inventory (in-row / in-rack) — floor placement + SNMP like cooling units
            self::ensureTable(
                'ups_units',
                "CREATE TABLE ups_units (
                    ups_id INT IDENTITY(1,1) PRIMARY KEY,
                    name NVARCHAR(150) NOT NULL,
                    ups_scope NVARCHAR(20) NOT NULL CONSTRAINT DF_ups_scope DEFAULT 'in_row',
                    room_id INT NULL,
                    row_id INT NULL,
                    cabinet_id INT NULL,
                    zone_id INT NULL,
                    manufacturer NVARCHAR(100) NULL,
                    model NVARCHAR(100) NULL,
                    serial_no NVARCHAR(100) NULL,
                    asset_tag NVARCHAR(100) NULL,
                    primary_ip NVARCHAR(45) NULL,
                    hostname NVARCHAR(255) NULL,
                    rated_kva DECIMAL(10,2) NULL,
                    rated_kw DECIMAL(10,2) NULL,
                    phases INT NULL,
                    status NVARCHAR(30) NOT NULL CONSTRAINT DF_ups_status DEFAULT 'production',
                    pos_x DECIMAL(10,3) NULL,
                    pos_y DECIMAL(10,3) NULL,
                    pos_z DECIMAL(10,3) NULL,
                    rotation_deg DECIMAL(8,2) NULL,
                    front_facing NVARCHAR(10) NULL,
                    width_mm INT NULL,
                    depth_mm INT NULL,
                    height_mm INT NULL,
                    color_hex NVARCHAR(7) NULL,
                    snmp_enabled BIT NOT NULL CONSTRAINT DF_ups_snmp_en DEFAULT 0,
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
                    snmp_engine_id NVARCHAR(80) NULL,
                    snmp_site_template_id INT NULL,
                    snmp_auto_poll BIT NOT NULL CONSTRAINT DF_ups_snmp_auto DEFAULT 0,
                    snmp_last_poll_at DATETIME2 NULL,
                    last_poll_json NVARCHAR(MAX) NULL,
                    last_output_status NVARCHAR(40) NULL,
                    last_load_pct DECIMAL(8,2) NULL,
                    last_battery_pct DECIMAL(8,2) NULL,
                    last_runtime_min DECIMAL(10,2) NULL,
                    last_input_voltage DECIMAL(10,2) NULL,
                    last_output_voltage DECIMAL(10,2) NULL,
                    last_internal_temp_c DECIMAL(8,2) NULL,
                    warranty_provider NVARCHAR(150) NULL,
                    warranty_end DATE NULL,
                    install_date DATE NULL,
                    manufacture_date DATE NULL,
                    notes NVARCHAR(MAX) NULL,
                    is_active BIT NOT NULL CONSTRAINT DF_ups_active DEFAULT 1,
                    created_at DATETIME2 NOT NULL CONSTRAINT DF_ups_created DEFAULT SYSUTCDATETIME(),
                    updated_at DATETIME2 NOT NULL CONSTRAINT DF_ups_updated DEFAULT SYSUTCDATETIME()
                )"
            );
            // Additive warranty / lifecycle columns for installs created before 0.3.75
            self::ensureColumn('ups_units', 'warranty_provider', 'NVARCHAR(150) NULL');
            self::ensureColumn('ups_units', 'warranty_end', 'DATE NULL');
            self::ensureColumn('ups_units', 'install_date', 'DATE NULL');
            self::ensureColumn('ups_units', 'manufacture_date', 'DATE NULL');
            self::ensureColumn('ups_units', 'po_number', 'NVARCHAR(100) NULL');
            self::ensureColumn('ups_units', 'purchase_date', 'DATE NULL');
            self::ensureColumn('ups_units', 'purchase_cost', 'DECIMAL(14,2) NULL');
            self::ensureColumn('ups_units', 'purchase_vendor', 'NVARCHAR(150) NULL');
            self::ensureColumn('ups_units', 'asset_tag', 'NVARCHAR(100) NULL');
            self::ensureColumn('ups_units', 'ups_template_id', 'INT NULL');
            self::ensureColumn('ups_units', 'last_input_freq', 'DECIMAL(10,3) NULL');
            self::ensureColumn('ups_units', 'last_output_freq', 'DECIMAL(10,3) NULL');
            self::ensureColumn('ups_units', 'last_output_current', 'DECIMAL(12,3) NULL');
            self::ensureColumn('ups_units', 'snmp_engine_id', 'NVARCHAR(80) NULL');

            // UPS poll history for dashboard / zone charts
            self::ensureTable(
                'ups_readings',
                "CREATE TABLE ups_readings (
                    reading_id BIGINT IDENTITY(1,1) PRIMARY KEY,
                    ups_id INT NOT NULL,
                    load_pct DECIMAL(8,2) NULL,
                    battery_pct DECIMAL(8,2) NULL,
                    runtime_min DECIMAL(10,2) NULL,
                    output_status NVARCHAR(80) NULL,
                    estimated_watts DECIMAL(12,2) NULL,
                    input_voltage DECIMAL(10,2) NULL,
                    output_voltage DECIMAL(10,2) NULL,
                    input_freq DECIMAL(10,3) NULL,
                    output_freq DECIMAL(10,3) NULL,
                    output_current DECIMAL(12,3) NULL,
                    polled_at DATETIME2 NOT NULL CONSTRAINT DF_ups_rd_at DEFAULT SYSUTCDATETIME()
                )"
            );
            self::ensureColumn('ups_readings', 'input_voltage', 'DECIMAL(10,2) NULL');
            self::ensureColumn('ups_readings', 'output_voltage', 'DECIMAL(10,2) NULL');
            self::ensureColumn('ups_readings', 'input_freq', 'DECIMAL(10,3) NULL');
            self::ensureColumn('ups_readings', 'output_freq', 'DECIMAL(10,3) NULL');
            self::ensureColumn('ups_readings', 'output_current', 'DECIMAL(12,3) NULL');
            try {
                $hasUpsRd = Database::fetchValue(
                    "SELECT 1 FROM sys.tables WHERE name = 'ups_readings' AND SCHEMA_NAME(schema_id) = 'dbo'"
                );
                if ($hasUpsRd) {
                    $idx = Database::fetchValue(
                        "SELECT 1 FROM sys.indexes WHERE name = 'IX_ups_readings_ups_time' AND object_id = OBJECT_ID('dbo.ups_readings')"
                    );
                    if (!$idx) {
                        Database::query(
                            'CREATE NONCLUSTERED INDEX IX_ups_readings_ups_time ON ups_readings(ups_id, polled_at DESC)'
                        );
                    }
                }
            } catch (Throwable $e) {
                // ignore index race
            }

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

            self::ensureIpam();
            self::ensureAirflow();

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
            'power_zones', 'pdus', 'pdu_outlets',
        ];
        $managed = [
            'datacenters' => ['north_edge'],
            'cabinet_rows' => ['zone_id', 'color_hex'],
            'departments' => ['color_hex'],
            'department_group_maps' => ['map_id', 'department_id', 'auth_source', 'group_id'],
            'devices' => [
                'parent_device_id', 'manufacture_date', 'weight_kg', 'num_data_ports', 'num_power_ports',
                'warranty_provider', 'tags', 'snmp_version', 'snmp_community', 'snmp_fail_count',
                'snmp_v3_profile_id', 'snmp_site_template_id', 'snmp_auto_poll', 'snmp_engine_id',
                'snmp_last_poll_at', 'snmp_last_poll_watts', 'snmp_last_poll_amps', 'last_poll_json',
                'idrac_host', 'icmp_monitor', 'icmp_fail_count', 'icmp_last_at', 'icmp_last_ok',
                'po_number', 'purchase_date', 'purchase_cost', 'purchase_vendor',
                'rma_number', 'rma_status', 'rma_notes', 'warranty_notify_for_end',
            ],
            'snmp_site_oid_templates' => ['template_id', 'name', 'oid_map'],
            'pdu_templates' => ['template_id', 'name', 'fields_json'],
            'snmp_v3_profiles' => ['profile_id', 'name', 'security_name'],
            'alert_subscriptions' => ['subscription_id', 'name', 'scope', 'categories'],
            'snmp_thresholds' => ['threshold_id', 'name', 'entity_type', 'metric_key'],
            'snmp_threshold_state' => ['threshold_id', 'entity_type', 'entity_id'],
            'device_notes' => ['note_id', 'device_id', 'note_text'],
            'pdus' => [
                'mount_style', 'position_u', 'u_height', 'snmp_community', 'phases',
                'output_mode', 'snmp_site_template_id', 'snmp_auto_poll', 'pdu_template_id',
                'snmp_engine_id',
                'last_poll_phases', 'room_id', 'pos_x', 'pos_y',
                'icmp_monitor', 'icmp_fail_count', 'icmp_last_at', 'icmp_last_ok',
                'include_in_site_load',
                'po_number', 'purchase_date', 'purchase_cost', 'purchase_vendor',
                'warranty_provider', 'warranty_end', 'install_date',
            ],
            'pdu_outlets' => ['rated_amps', 'device_power_supply_id'],
            'power_alert_state' => ['alert_key', 'pdu_id', 'severity'],
            'power_alert_queue' => ['queue_id', 'alert_key', 'pdu_id'],
            'pdu_breakers' => ['breaker_id', 'pdu_id', 'slots_json'],
            'device_power_supplies' => ['power_supply_id', 'device_id', 'name'],
            'device_templates' => ['power_supplies_json'],
            'import_id_map' => ['map_id', 'source', 'entity_type', 'source_id', 'local_id'],
            'disposal_vendors' => ['vendor_id', 'name'],
            'disposals' => ['stage', 'vendor_id', 'change_ticket', 'notification_sent', 'notification_sent_at'],
            'notifications' => ['notification_id', 'user_id', 'title', 'category', 'is_read', 'is_cleared', 'cleared_at'],
            'password_reset_tokens' => ['token_id', 'user_id', 'token_hash', 'expires_at'],
            'api_tokens' => ['token_id', 'user_id', 'token_prefix', 'token_hash', 'scopes'],
            'users' => ['is_service_account', 'can_login'],
            'asset_events' => ['event_id', 'device_id', 'event_type', 'summary', 'occurred_at'],
            'cable_paths' => [
                'path_id', 'room_id', 'name', 'path_type', 'waypoints', 'color_hex',
                'media_class', 'path_kind', 'feed_to', 'width_m', 'elevation_m', 'is_active',
                'path_code', 'segment_class',
            ],
            'cables' => [
                'cable_id', 'cable_label', 'media_type', 'path_id', 'circuit_id', 'speed',
                'color_hex', 'cable_role', 'strand_count',
            ],
            'work_orders' => [
                'work_order_id', 'title', 'work_type', 'status', 'change_ticket', 'checklist_json',
                'itsm_provider', 'itsm_request_id', 'itsm_display_id',
            ],
            'work_order_items' => [
                'item_id', 'work_order_id', 'device_id', 'from_cabinet_id', 'to_cabinet_id', 'item_status',
            ],
            'cabinet_audits' => ['cabinet_audit_id', 'cabinet_id', 'audited_at', 'snapshot_json'],
            'cabinet_audit_photos' => ['photo_id', 'cabinet_audit_id', 'cabinet_id', 'rel_path'],
            'cabinets' => ['audit_interval_days'],
            'ipam_prefixes' => [
                'prefix_id', 'cidr', 'name', 'vlan_id', 'vrf', 'gateway', 'role',
                'prefix_len', 'ip_version', 'network_int', 'dhcp_start', 'dhcp_end',
                'track', 'parent_id',
            ],
            'ipam_addresses' => [
                'address_id', 'prefix_id', 'ip', 'ip_int', 'status', 'hostname',
                'device_id', 'pdu_id', 'ups_id',
            ],
            'ipam_align_groups' => ['group_id', 'name', 'vrf', 'idx_from', 'idx_to'],
            'ipam_align_members' => ['member_id', 'group_id', 'prefix_id', 'label', 'sort_order'],
            'ipam_align_slots' => ['slot_id', 'group_id', 'idx', 'hostname'],
            'role_group_maps' => ['map_id', 'role_id', 'auth_source', 'group_id'],
            'auth_sessions' => ['session_id', 'user_id', 'last_seen_at', 'expires_at'],
            'ups_units' => [
                'ups_id', 'name', 'ups_scope', 'room_id', 'zone_id', 'pos_x', 'pos_y',
                'snmp_enabled', 'snmp_site_template_id', 'snmp_auto_poll', 'ups_template_id',
                'last_load_pct', 'last_battery_pct', 'last_output_status',
            ],
            'ups_templates' => ['template_id', 'name', 'fields_json'],
            'ups_readings' => [
                'reading_id', 'ups_id', 'load_pct', 'battery_pct', 'runtime_min', 'polled_at',
            ],
            'cooling_units' => [
                'cooling_unit_id', 'name', 'unit_type', 'unit_role', 'cooling_medium',
                'room_id', 'pos_x', 'pos_y', 'snmp_enabled', 'primary_ip', 'snmp_engine_id',
            ],
            'airflow_anchors' => [
                'anchor_id', 'room_id', 'kind', 'name', 'shape', 'pos_x', 'pos_y', 'pos_z',
            ],
            'env_sensors' => [
                'sensor_id', 'name', 'sensor_kind', 'host_type', 'room_id',
                'cooling_unit_id', 'pdu_id', 'device_id', 'last_value',
                'snmp_oid', 'snmp_index', 'pos_x', 'pos_y', 'pos_z',
            ],
            'env_readings' => ['reading_id', 'sensor_id', 'value', 'recorded_at'],
            'device_snmp_readings' => ['reading_id', 'device_id', 'metric_name', 'metric_value', 'polled_at'],
        ];
        $core = array_values(array_unique(array_merge($core, [
            'cooling_units', 'env_sensors', 'env_readings', 'airflow_anchors',
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
     * Existing non-admin role permission JSON is left alone (Users → Platform roles matrix).
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
            $star = in_array($name, ['Global Admin', 'Administrator'], true);
            if ($existing) {
                $fields = [
                    'description' => $def['description'],
                    'is_system' => 1,
                ];
                // Global Admin stays full access. Other system roles keep the matrix
                // (Users → Platform roles); do not wipe checkmarks on schema ensure.
                if ($star) {
                    $fields['permissions'] = json_encode(['*']);
                }
                Database::update('roles', $fields, 'role_id = :id', [':id' => (int)$existing['role_id']]);
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

    /**
     * IP address plan (statics + reserved + DHCP fences). Safe to call on every IPAM page load
     * even when the version stamp already skipped the full ensure().
     */
    public static function ensureIpam(): void
    {
        self::ensureTable(
            'ipam_prefixes',
            "CREATE TABLE ipam_prefixes (
                prefix_id INT IDENTITY(1,1) PRIMARY KEY,
                cidr NVARCHAR(50) NOT NULL,
                name NVARCHAR(150) NULL,
                vlan_id INT NULL,
                vrf NVARCHAR(80) NOT NULL CONSTRAINT DF_ipam_pfx_vrf DEFAULT 'default',
                gateway NVARCHAR(45) NULL,
                role NVARCHAR(40) NULL,
                description NVARCHAR(500) NULL,
                notes NVARCHAR(MAX) NULL,
                prefix_len INT NOT NULL,
                ip_version INT NOT NULL CONSTRAINT DF_ipam_pfx_ver DEFAULT 4,
                network_int BIGINT NULL,
                dhcp_start NVARCHAR(45) NULL,
                dhcp_end NVARCHAR(45) NULL,
                room_id INT NULL,
                track NVARCHAR(20) NOT NULL CONSTRAINT DF_ipam_pfx_track DEFAULT 'hosts',
                parent_id INT NULL,
                is_active BIT NOT NULL CONSTRAINT DF_ipam_pfx_active DEFAULT 1,
                created_at DATETIME2 NOT NULL CONSTRAINT DF_ipam_pfx_created DEFAULT SYSUTCDATETIME(),
                updated_at DATETIME2 NOT NULL CONSTRAINT DF_ipam_pfx_updated DEFAULT SYSUTCDATETIME()
            )"
        );
        self::ensureTable(
            'ipam_addresses',
            "CREATE TABLE ipam_addresses (
                address_id INT IDENTITY(1,1) PRIMARY KEY,
                prefix_id INT NOT NULL,
                ip NVARCHAR(45) NOT NULL,
                ip_int BIGINT NULL,
                vrf NVARCHAR(80) NOT NULL CONSTRAINT DF_ipam_addr_vrf DEFAULT 'default',
                status NVARCHAR(20) NOT NULL CONSTRAINT DF_ipam_addr_st DEFAULT 'assigned',
                hostname NVARCHAR(255) NULL,
                mac_address NVARCHAR(64) NULL,
                description NVARCHAR(500) NULL,
                notes NVARCHAR(MAX) NULL,
                device_id INT NULL,
                pdu_id INT NULL,
                ups_id INT NULL,
                last_seen_at DATETIME2 NULL,
                created_at DATETIME2 NOT NULL CONSTRAINT DF_ipam_addr_created DEFAULT SYSUTCDATETIME(),
                updated_at DATETIME2 NOT NULL CONSTRAINT DF_ipam_addr_updated DEFAULT SYSUTCDATETIME()
            )"
        );
        self::ensureColumn(
            'ipam_prefixes',
            'track',
            "NVARCHAR(20) NOT NULL CONSTRAINT DF_ipam_pfx_track DEFAULT 'hosts'"
        );
        self::ensureColumn('ipam_prefixes', 'parent_id', 'INT NULL');
        self::ensureTable(
            'ipam_align_groups',
            "CREATE TABLE ipam_align_groups (
                group_id INT IDENTITY(1,1) PRIMARY KEY,
                name NVARCHAR(150) NOT NULL,
                description NVARCHAR(500) NULL,
                vrf NVARCHAR(80) NOT NULL CONSTRAINT DF_ipam_ag_vrf DEFAULT 'default',
                idx_from INT NOT NULL CONSTRAINT DF_ipam_ag_from DEFAULT 1,
                idx_to INT NOT NULL CONSTRAINT DF_ipam_ag_to DEFAULT 254,
                created_at DATETIME2 NOT NULL CONSTRAINT DF_ipam_ag_created DEFAULT SYSUTCDATETIME(),
                updated_at DATETIME2 NOT NULL CONSTRAINT DF_ipam_ag_updated DEFAULT SYSUTCDATETIME()
            )"
        );
        self::ensureTable(
            'ipam_align_members',
            "CREATE TABLE ipam_align_members (
                member_id INT IDENTITY(1,1) PRIMARY KEY,
                group_id INT NOT NULL,
                prefix_id INT NOT NULL,
                label NVARCHAR(80) NULL,
                sort_order INT NOT NULL CONSTRAINT DF_ipam_am_sort DEFAULT 0
            )"
        );
        self::ensureTable(
            'ipam_align_slots',
            "CREATE TABLE ipam_align_slots (
                slot_id INT IDENTITY(1,1) PRIMARY KEY,
                group_id INT NOT NULL,
                idx INT NOT NULL,
                hostname NVARCHAR(255) NOT NULL,
                notes NVARCHAR(500) NULL,
                created_at DATETIME2 NOT NULL CONSTRAINT DF_ipam_as_created DEFAULT SYSUTCDATETIME(),
                updated_at DATETIME2 NOT NULL CONSTRAINT DF_ipam_as_updated DEFAULT SYSUTCDATETIME()
            )"
        );
    }

    /**
     * Ceiling supply vents and return grilles for 3D airflow particles.
     * Safe to call from the floor-plan API even when the version stamp skipped ensure().
     */
    public static function ensureAirflow(): void
    {
        self::ensureTable(
            'airflow_anchors',
            "CREATE TABLE airflow_anchors (
                anchor_id INT IDENTITY(1,1) PRIMARY KEY,
                room_id INT NOT NULL,
                kind NVARCHAR(20) NOT NULL,
                name NVARCHAR(80) NULL,
                pos_x FLOAT NOT NULL,
                pos_y FLOAT NOT NULL,
                pos_z FLOAT NULL,
                width_m FLOAT NOT NULL CONSTRAINT DF_af_w DEFAULT 0.6,
                depth_m FLOAT NOT NULL CONSTRAINT DF_af_d DEFAULT 0.6,
                rotation_deg FLOAT NOT NULL CONSTRAINT DF_af_rot DEFAULT 0,
                color_hex NVARCHAR(7) NULL,
                cooling_unit_id INT NULL,
                is_locked BIT NOT NULL CONSTRAINT DF_af_lock DEFAULT 0,
                is_active BIT NOT NULL CONSTRAINT DF_af_active DEFAULT 1,
                notes NVARCHAR(255) NULL,
                created_at DATETIME2 NOT NULL CONSTRAINT DF_af_created DEFAULT SYSUTCDATETIME(),
                updated_at DATETIME2 NOT NULL CONSTRAINT DF_af_updated DEFAULT SYSUTCDATETIME()
            )"
        );
        self::ensureColumn(
            'airflow_anchors',
            'shape',
            "NVARCHAR(20) NOT NULL CONSTRAINT DF_af_shape DEFAULT 'circle'"
        );
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
