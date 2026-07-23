-- ColdAisle - SQL Server Schema
-- Data Center Infrastructure Management for IIS / SQL Server
-- Version 1.0.0

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'schema_version')
BEGIN
    CREATE TABLE schema_version (
        version NVARCHAR(20) NOT NULL,
        applied_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
    );
    INSERT INTO schema_version (version) VALUES ('1.0.0');
END
GO

-- ============================================================
-- Core Configuration & Auth
-- ============================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'settings')
CREATE TABLE settings (
    setting_key NVARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value NVARCHAR(MAX) NULL,
    category NVARCHAR(50) NOT NULL DEFAULT 'general',
    updated_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'roles')
CREATE TABLE roles (
    role_id INT IDENTITY(1,1) PRIMARY KEY,
    name NVARCHAR(50) NOT NULL UNIQUE,
    description NVARCHAR(255) NULL,
    permissions NVARCHAR(MAX) NULL, -- JSON array of permission keys
    is_system BIT NOT NULL DEFAULT 0
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'users')
CREATE TABLE users (
    user_id INT IDENTITY(1,1) PRIMARY KEY,
    username NVARCHAR(100) NOT NULL UNIQUE,
    email NVARCHAR(255) NOT NULL,
    display_name NVARCHAR(150) NULL,
    password_hash NVARCHAR(255) NULL, -- null for SSO/LDAP-only
    auth_source NVARCHAR(20) NOT NULL DEFAULT 'local', -- local, ldaps, entra
    external_id NVARCHAR(255) NULL, -- LDAP DN or Entra OID
    role_id INT NOT NULL REFERENCES roles(role_id),
    department_id INT NULL,
    is_active BIT NOT NULL DEFAULT 1,
    must_change_password BIT NOT NULL DEFAULT 0,
    last_login DATETIME2 NULL,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'auth_sessions')
CREATE TABLE auth_sessions (
    session_id NVARCHAR(128) NOT NULL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    ip_address NVARCHAR(45) NULL,
    user_agent NVARCHAR(500) NULL,
    expires_at DATETIME2 NOT NULL,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'audit_log')
CREATE TABLE audit_log (
    audit_id BIGINT IDENTITY(1,1) PRIMARY KEY,
    user_id INT NULL,
    username NVARCHAR(100) NULL,
    action NVARCHAR(50) NOT NULL,
    entity_type NVARCHAR(50) NULL,
    entity_id INT NULL,
    details NVARCHAR(MAX) NULL,
    ip_address NVARCHAR(45) NULL,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

CREATE NONCLUSTERED INDEX IX_audit_log_created ON audit_log(created_at DESC);
CREATE NONCLUSTERED INDEX IX_audit_log_entity ON audit_log(entity_type, entity_id);
GO

-- ============================================================
-- Organizational
-- ============================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'departments')
CREATE TABLE departments (
    department_id INT IDENTITY(1,1) PRIMARY KEY,
    name NVARCHAR(100) NOT NULL UNIQUE,
    code NVARCHAR(20) NULL,
    manager_name NVARCHAR(150) NULL,
    contact_email NVARCHAR(255) NULL,
    contact_phone NVARCHAR(50) NULL,
    color_hex NVARCHAR(7) NOT NULL DEFAULT '#3b82f6', -- rack outline / UI badge
    notes NVARCHAR(MAX) NULL,
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

-- Map external security groups (LDAPS / Entra ID) → department (applied at login later)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'department_group_maps')
CREATE TABLE department_group_maps (
    map_id INT IDENTITY(1,1) PRIMARY KEY,
    department_id INT NOT NULL REFERENCES departments(department_id) ON DELETE CASCADE,
    auth_source NVARCHAR(20) NOT NULL, -- ldaps, entra
    group_id NVARCHAR(255) NOT NULL, -- LDAP DN/SID or Entra group object id
    group_name NVARCHAR(255) NULL, -- friendly name for admins
    is_active BIT NOT NULL DEFAULT 1,
    notes NVARCHAR(255) NULL,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    CONSTRAINT UQ_dept_group_map UNIQUE (auth_source, group_id)
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'contacts')
CREATE TABLE contacts (
    contact_id INT IDENTITY(1,1) PRIMARY KEY,
    department_id INT NULL REFERENCES departments(department_id),
    first_name NVARCHAR(100) NOT NULL,
    last_name NVARCHAR(100) NOT NULL,
    email NVARCHAR(255) NULL,
    phone NVARCHAR(50) NULL,
    title NVARCHAR(100) NULL,
    notes NVARCHAR(MAX) NULL,
    is_active BIT NOT NULL DEFAULT 1
);
GO

-- ============================================================
-- Facilities Hierarchy: Site > Data Center > Room > Row > Cabinet
-- ============================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'sites')
CREATE TABLE sites (
    site_id INT IDENTITY(1,1) PRIMARY KEY,
    name NVARCHAR(100) NOT NULL,
    code NVARCHAR(20) NULL,
    address NVARCHAR(500) NULL,
    city NVARCHAR(100) NULL,
    state NVARCHAR(50) NULL,
    postal_code NVARCHAR(20) NULL,
    country NVARCHAR(50) NULL,
    timezone NVARCHAR(50) NOT NULL DEFAULT 'UTC',
    contact_name NVARCHAR(150) NULL,
    contact_phone NVARCHAR(50) NULL,
    notes NVARCHAR(MAX) NULL,
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'datacenters')
CREATE TABLE datacenters (
    datacenter_id INT IDENTITY(1,1) PRIMARY KEY,
    site_id INT NOT NULL REFERENCES sites(site_id),
    name NVARCHAR(100) NOT NULL,
    code NVARCHAR(20) NULL,
    square_footage DECIMAL(12,2) NULL,
    max_kw DECIMAL(12,2) NULL,
    delivery_address NVARCHAR(500) NULL,
    notes NVARCHAR(MAX) NULL,
    -- Floor plan canvas dimensions (meters)
    floor_width_m DECIMAL(10,2) NOT NULL DEFAULT 50.0,
    floor_depth_m DECIMAL(10,2) NOT NULL DEFAULT 30.0,
    -- Which edge of the 2D plan drawing is geographic North: top|right|bottom|left
    north_edge NVARCHAR(10) NOT NULL DEFAULT 'top',
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'rooms')
CREATE TABLE rooms (
    room_id INT IDENTITY(1,1) PRIMARY KEY,
    datacenter_id INT NOT NULL REFERENCES datacenters(datacenter_id),
    name NVARCHAR(100) NOT NULL,
    code NVARCHAR(20) NULL,
    floor_level NVARCHAR(20) NULL,
    width_m DECIMAL(10,2) NOT NULL DEFAULT 20.0,
    depth_m DECIMAL(10,2) NOT NULL DEFAULT 15.0,
    pos_x DECIMAL(10,2) NOT NULL DEFAULT 0,
    pos_y DECIMAL(10,2) NOT NULL DEFAULT 0,
    notes NVARCHAR(MAX) NULL,
    is_active BIT NOT NULL DEFAULT 1
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'cabinet_rows')
CREATE TABLE cabinet_rows (
    row_id INT IDENTITY(1,1) PRIMARY KEY,
    room_id INT NOT NULL REFERENCES rooms(room_id),
    name NVARCHAR(50) NOT NULL,
    data_center_id INT NULL, -- denormalized for convenience
    zone_id INT NULL, -- optional link to power_zones (row → zone)
    color_hex NVARCHAR(7) NULL,
    pos_x DECIMAL(10,2) NOT NULL DEFAULT 0,
    pos_y DECIMAL(10,2) NOT NULL DEFAULT 0,
    rotation_deg DECIMAL(6,2) NOT NULL DEFAULT 0,
    notes NVARCHAR(MAX) NULL
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'cabinets')
CREATE TABLE cabinets (
    cabinet_id INT IDENTITY(1,1) PRIMARY KEY,
    room_id INT NOT NULL REFERENCES rooms(room_id),
    row_id INT NULL REFERENCES cabinet_rows(row_id),
    name NVARCHAR(50) NOT NULL,
    location_tag NVARCHAR(50) NULL,
    -- Physical dimensions (mm for width/depth, U for height)
    u_height INT NOT NULL DEFAULT 42,
    width_mm INT NOT NULL DEFAULT 600,
    depth_mm INT NOT NULL DEFAULT 1200,
    max_weight_kg DECIMAL(10,2) NULL,
    max_kw DECIMAL(10,2) NULL,
    -- Floor plan position (meters from room origin)
    pos_x DECIMAL(10,3) NOT NULL DEFAULT 0,
    pos_y DECIMAL(10,3) NOT NULL DEFAULT 0,
    pos_z DECIMAL(10,3) NOT NULL DEFAULT 0,
    rotation_deg DECIMAL(6,2) NOT NULL DEFAULT 0,
    -- Colors for 3D visualization
    color_hex NVARCHAR(7) NOT NULL DEFAULT '#2d3748',
    front_facing NVARCHAR(10) NOT NULL DEFAULT 'north', -- north,south,east,west
    model_key NVARCHAR(50) NULL, -- template key for 3D model variant
    notes NVARCHAR(MAX) NULL,
    installation_date DATE NULL,
    -- Physical audit cadence override in days (NULL = site default from settings.audit_interval_days)
    audit_interval_days INT NULL,
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

CREATE NONCLUSTERED INDEX IX_cabinets_room ON cabinets(room_id);
GO

-- ============================================================
-- Device Catalog
-- ============================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'manufacturers')
CREATE TABLE manufacturers (
    manufacturer_id INT IDENTITY(1,1) PRIMARY KEY,
    name NVARCHAR(100) NOT NULL UNIQUE,
    website NVARCHAR(255) NULL,
    support_url NVARCHAR(255) NULL,
    notes NVARCHAR(MAX) NULL
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'device_templates')
CREATE TABLE device_templates (
    template_id INT IDENTITY(1,1) PRIMARY KEY,
    manufacturer_id INT NULL REFERENCES manufacturers(manufacturer_id),
    model NVARCHAR(100) NOT NULL,
    device_type NVARCHAR(50) NOT NULL, -- server,switch,router,pdu,ups,storage,chassis,blade,kvm,other
    u_height INT NOT NULL DEFAULT 1,
    weight_kg DECIMAL(8,2) NULL,
    watts DECIMAL(10,2) NULL,
    num_power_ports INT NOT NULL DEFAULT 2,
    num_data_ports INT NOT NULL DEFAULT 0,
    front_picture NVARCHAR(255) NULL,
    rear_picture NVARCHAR(255) NULL,
    snmp_template NVARCHAR(50) NULL,
    notes NVARCHAR(MAX) NULL,
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'devices')
CREATE TABLE devices (
    device_id INT IDENTITY(1,1) PRIMARY KEY,
    cabinet_id INT NULL REFERENCES cabinets(cabinet_id),
    template_id INT NULL REFERENCES device_templates(template_id),
    department_id INT NULL REFERENCES departments(department_id),
    parent_device_id INT NULL, -- chassis parent (blade servers)
    label NVARCHAR(100) NOT NULL, -- Device Name
    serial_no NVARCHAR(100) NULL,
    asset_tag NVARCHAR(100) NULL, -- Asset Number
    device_type NVARCHAR(50) NOT NULL DEFAULT 'server',
    -- server,pdu,router,network_switch,storage_array,storage_switch,kvm,monitor,nvr,chassis,...
    manufacturer NVARCHAR(100) NULL,
    model NVARCHAR(100) NULL,
    manufacture_date DATE NULL,
    -- Position in rack (bottom U = 1)
    position_u INT NULL,
    u_height INT NOT NULL DEFAULT 1,
    half_depth BIT NOT NULL DEFAULT 0,
    back_side BIT NOT NULL DEFAULT 0, -- 0=Front, 1=Rear of cabinet
    weight_kg DECIMAL(10,2) NULL,
    num_data_ports INT NULL,
    num_power_ports INT NULL,
    -- Network / management
    primary_ip NVARCHAR(45) NULL,
    mgmt_ip NVARCHAR(45) NULL,
    hostname NVARCHAR(255) NULL,
    -- Power
    nominal_watts DECIMAL(10,2) NULL,
    -- Status lifecycle: production,testing,disposed,development,reserved,spare
    status NVARCHAR(30) NOT NULL DEFAULT 'production',
    install_date DATE NULL,
    warranty_provider NVARCHAR(150) NULL,
    warranty_end DATE NULL,
    owner_contact_id INT NULL REFERENCES contacts(contact_id),
    tags NVARCHAR(500) NULL, -- comma-separated
    -- SNMP
    snmp_version NVARCHAR(10) NULL, -- 1, 2c, 3
    snmp_community NVARCHAR(100) NULL, -- read-only community
    snmp_fail_count INT NOT NULL DEFAULT 0, -- consecutive poll failures
    snmp_v3_profile_id INT NULL, -- optional link to snmp_v3_profiles
    snmp_v3_user NVARCHAR(100) NULL, -- SNMPv3 security name / user
    snmp_v3_sec_level NVARCHAR(30) NULL, -- noAuthNoPriv, authNoPriv, authPriv
    snmp_v3_auth_proto NVARCHAR(20) NULL, -- MD5, SHA, SHA256,...
    snmp_v3_auth_pass NVARCHAR(255) NULL,
    snmp_v3_priv_proto NVARCHAR(20) NULL, -- DES, AES, AES256,...
    snmp_v3_priv_pass NVARCHAR(255) NULL,
    snmp_v3_context NVARCHAR(100) NULL,
    -- Site OID template (discovered Vendor+Model) — OIDs stored once, not per device
    snmp_site_template_id INT NULL,
    snmp_auto_poll BIT NOT NULL DEFAULT 0, -- include in SNMP scheduler when template assigned
    snmp_last_poll_at DATETIME2 NULL,
    snmp_last_poll_watts DECIMAL(18,4) NULL,
    snmp_last_poll_amps DECIMAL(18,4) NULL,
    notes NVARCHAR(MAX) NULL, -- legacy freeform; prefer device_notes
    custom_fields NVARCHAR(MAX) NULL, -- JSON
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

CREATE NONCLUSTERED INDEX IX_devices_cabinet ON devices(cabinet_id);
CREATE NONCLUSTERED INDEX IX_devices_status ON devices(status);
CREATE NONCLUSTERED INDEX IX_devices_label ON devices(label);
GO

-- Timestamped device notes
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'device_notes')
CREATE TABLE device_notes (
    note_id INT IDENTITY(1,1) PRIMARY KEY,
    device_id INT NOT NULL REFERENCES devices(device_id) ON DELETE CASCADE,
    user_id INT NULL,
    username NVARCHAR(100) NULL,
    note_text NVARCHAR(MAX) NOT NULL,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

CREATE NONCLUSTERED INDEX IX_device_notes_device ON device_notes(device_id, created_at DESC);
GO

-- Chassis / blade parent-child (optional multi-parent graph; prefer parent_device_id)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'device_children')
CREATE TABLE device_children (
    parent_device_id INT NOT NULL REFERENCES devices(device_id),
    child_device_id INT NOT NULL REFERENCES devices(device_id),
    slot NVARCHAR(20) NULL,
    PRIMARY KEY (parent_device_id, child_device_id)
);
GO

-- ============================================================
-- Ports & Cabling
-- ============================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'device_ports')
CREATE TABLE device_ports (
    port_id INT IDENTITY(1,1) PRIMARY KEY,
    device_id INT NOT NULL REFERENCES devices(device_id) ON DELETE CASCADE,
    port_type NVARCHAR(20) NOT NULL, -- data, power
    port_number INT NOT NULL,
    label NVARCHAR(100) NULL,
    media_type NVARCHAR(50) NULL, -- RJ45, SFP, SFP+, QSFP, C13, C19, IEC309, etc.
    speed NVARCHAR(30) NULL, -- 1G, 10G, 25G, 40G, 100G, 120V/15A, etc.
    mac_address NVARCHAR(17) NULL,
    notes NVARCHAR(255) NULL,
    CONSTRAINT UQ_device_port UNIQUE (device_id, port_type, port_number)
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'cable_paths')
CREATE TABLE cable_paths (
    path_id INT IDENTITY(1,1) PRIMARY KEY,
    room_id INT NULL REFERENCES rooms(room_id),
    name NVARCHAR(100) NOT NULL,
    path_type NVARCHAR(30) NOT NULL DEFAULT 'overhead', -- overhead, underfloor, tray, conduit
    waypoints NVARCHAR(MAX) NULL, -- JSON array of {x,y,z}
    color_hex NVARCHAR(7) NOT NULL DEFAULT '#38bdf8',
    notes NVARCHAR(MAX) NULL
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'cables')
CREATE TABLE cables (
    cable_id INT IDENTITY(1,1) PRIMARY KEY,
    cable_label NVARCHAR(100) NULL,
    media_type NVARCHAR(50) NULL,
    length_m DECIMAL(8,2) NULL,
    color NVARCHAR(30) NULL,
    a_port_id INT NULL REFERENCES device_ports(port_id),
    b_port_id INT NULL REFERENCES device_ports(port_id),
    path_id INT NULL REFERENCES cable_paths(path_id),
    status NVARCHAR(30) NOT NULL DEFAULT 'active', -- active, planned, retired
    notes NVARCHAR(MAX) NULL,
    installed_at DATETIME2 NULL,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

-- ============================================================
-- Power Infrastructure
-- ============================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'power_zones')
CREATE TABLE power_zones (
    zone_id INT IDENTITY(1,1) PRIMARY KEY,
    datacenter_id INT NOT NULL REFERENCES datacenters(datacenter_id),
    name NVARCHAR(100) NOT NULL,
    description NVARCHAR(500) NULL,
    feed_type NVARCHAR(20) NOT NULL DEFAULT 'A', -- A, B, dual
    voltage INT NULL DEFAULT 208,
    max_amps DECIMAL(10,2) NULL,
    max_kw DECIMAL(10,2) NULL,
    color_hex NVARCHAR(7) NOT NULL DEFAULT '#ef4444',
    notes NVARCHAR(MAX) NULL
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'power_panels')
CREATE TABLE power_panels (
    panel_id INT IDENTITY(1,1) PRIMARY KEY,
    zone_id INT NULL REFERENCES power_zones(zone_id),
    room_id INT NULL REFERENCES rooms(room_id),
    name NVARCHAR(100) NOT NULL,
    panel_type NVARCHAR(50) NULL, -- main, sub, busway
    voltage INT NULL,
    phases INT NOT NULL DEFAULT 3,
    main_breaker_amps DECIMAL(10,2) NULL,
    num_poles INT NULL,
    location_notes NVARCHAR(255) NULL,
    notes NVARCHAR(MAX) NULL
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'power_circuits')
CREATE TABLE power_circuits (
    circuit_id INT IDENTITY(1,1) PRIMARY KEY,
    panel_id INT NOT NULL REFERENCES power_panels(panel_id),
    circuit_number NVARCHAR(20) NOT NULL,
    breaker_amps DECIMAL(8,2) NULL,
    pole_count INT NOT NULL DEFAULT 1,
    phase NVARCHAR(10) NULL, -- A, B, C, AB, BC, CA, ABC
    voltage INT NULL,
    label NVARCHAR(100) NULL,
    notes NVARCHAR(255) NULL
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'pdus')
CREATE TABLE pdus (
    pdu_id INT IDENTITY(1,1) PRIMARY KEY,
    device_id INT NULL REFERENCES devices(device_id), -- if rack-mounted as device
    cabinet_id INT NULL REFERENCES cabinets(cabinet_id),
    row_id INT NULL REFERENCES cabinet_rows(row_id),
    zone_id INT NULL REFERENCES power_zones(zone_id),
    circuit_id INT NULL REFERENCES power_circuits(circuit_id),
    name NVARCHAR(100) NOT NULL,
    pdu_scope NVARCHAR(20) NOT NULL DEFAULT 'rack', -- rack, row, room
    -- vertical_rear = 0U vertical rails (not drawn); u_mounted = occupies rack U
    mount_style NVARCHAR(20) NOT NULL DEFAULT 'vertical_rear',
    position_u INT NULL,
    u_height INT NULL DEFAULT 1,
    manufacturer NVARCHAR(100) NULL,
    model NVARCHAR(100) NULL,
    ip_address NVARCHAR(45) NULL,
    -- outlets = discrete receptacle list; breakers = pigtail/bus to breaker positions
    output_mode NVARCHAR(20) NOT NULL DEFAULT 'outlets', -- outlets | breakers
    num_outlets INT NOT NULL DEFAULT 24,
    num_breaker_slots INT NULL, -- total breaker positions when output_mode = breakers
    breaker_columns INT NULL DEFAULT 2, -- visual columns on panel (1–3)
    -- odd_right_even_left | odd_left_even_right | sequential_rows | sequential_columns | single_column | three_col_sequential
    breaker_layout NVARCHAR(40) NULL DEFAULT 'odd_right_even_left',
    rated_amps DECIMAL(8,2) NULL,
    rated_volts INT NULL, -- legacy; prefer input_voltage
    -- Electrical topology (especially row / room PDUs)
    phases INT NOT NULL DEFAULT 1, -- 1, 2 (split/two-phase), or 3
    -- single | split_phase | two_phase | wye | delta
    phase_wiring NVARCHAR(30) NULL DEFAULT 'single',
    input_voltage INT NULL, -- primary input (typically L-L for multi-phase)
    input_voltage_ln INT NULL, -- L-N when multi-phase / split-phase
    output_voltage INT NULL, -- primary output voltage
    output_voltage_ln INT NULL, -- secondary / L-N outlet voltage when dual
    sync_zone_voltage BIT NOT NULL DEFAULT 1, -- push voltage to linked power zone
    input_type NVARCHAR(50) NULL, -- input connector / NEMA / IEC
    -- SNMP
    snmp_enabled BIT NOT NULL DEFAULT 0,
    snmp_version NVARCHAR(10) NOT NULL DEFAULT '3',
    snmp_port INT NOT NULL DEFAULT 161,
    snmp_community NVARCHAR(100) NULL, -- v1/v2c public/read community
    snmp_security_name NVARCHAR(100) NULL,
    snmp_auth_protocol NVARCHAR(20) NULL, -- SHA, SHA256, MD5
    snmp_auth_passphrase NVARCHAR(255) NULL,
    snmp_priv_protocol NVARCHAR(20) NULL, -- AES, AES256, DES
    snmp_priv_passphrase NVARCHAR(255) NULL,
    snmp_site_template_id INT NULL, -- site Vendor+Model OID map (shared)
    snmp_auto_poll BIT NOT NULL DEFAULT 0, -- include in SNMP scheduler when template assigned
    snmp_context NVARCHAR(100) NULL,
    snmp_v3_sec_level NVARCHAR(30) NULL, -- noAuthNoPriv, authNoPriv, authPriv
    snmp_v3_profile_id INT NULL, -- optional link to snmp_v3_profiles (credentials copied onto PDU fields on save)
    last_poll_at DATETIME2 NULL,
    last_poll_watts DECIMAL(12,2) NULL,
    last_poll_amps DECIMAL(10,2) NULL,
    notes NVARCHAR(MAX) NULL,
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    -- Floor plan placement (row / room PDUs only; NULL = not on plan)
    room_id INT NULL REFERENCES rooms(room_id),
    pos_x DECIMAL(10,3) NULL,
    pos_y DECIMAL(10,3) NULL,
    pos_z DECIMAL(10,3) NULL DEFAULT 0,
    rotation_deg DECIMAL(8,2) NULL DEFAULT 0,
    front_facing NVARCHAR(10) NULL DEFAULT 'north', -- north|south|east|west
    width_mm INT NULL,  -- footprint width
    depth_mm INT NULL,  -- footprint depth
    height_mm INT NULL, -- physical height for 3D view (e.g. 1800 for floor RPP)
    color_hex NVARCHAR(7) NULL
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'pdu_outlets')
CREATE TABLE pdu_outlets (
    outlet_id INT IDENTITY(1,1) PRIMARY KEY,
    pdu_id INT NOT NULL REFERENCES pdus(pdu_id) ON DELETE CASCADE,
    outlet_number INT NOT NULL,
    label NVARCHAR(100) NULL,
    outlet_type NVARCHAR(30) NULL, -- C13, C19, 5-15R, L6-30R, etc. (NEMA/IEC)
    rated_amps DECIMAL(8,2) NULL,
    connected_device_id INT NULL REFERENCES devices(device_id),
    connected_power_port_id INT NULL REFERENCES device_ports(port_id),
    -- optional explicit link to device power supply line
    device_power_supply_id INT NULL,
    bank NVARCHAR(20) NULL,
    notes NVARCHAR(255) NULL,
    CONSTRAINT UQ_pdu_outlet UNIQUE (pdu_id, outlet_number)
);
GO

-- Breakers on row/room PDUs (pigtails / breaker panels). A breaker may span multiple slots.
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'pdu_breakers')
CREATE TABLE pdu_breakers (
    breaker_id INT IDENTITY(1,1) PRIMARY KEY,
    pdu_id INT NOT NULL REFERENCES pdus(pdu_id) ON DELETE CASCADE,
    breaker_number INT NOT NULL, -- display / logical number
    label NVARCHAR(100) NULL,
    -- JSON array of occupied slot numbers, e.g. [1,3,5] for multi-pole non-contiguous
    slots_json NVARCHAR(500) NOT NULL DEFAULT '[]',
    slot_start INT NULL, -- denormalized min(slot) for sorting / legacy
    slot_end INT NULL,   -- denormalized max(slot)
    rated_amps DECIMAL(8,2) NULL,
    phase NVARCHAR(20) NULL, -- A, B, C, ABC, etc.
    connected_cabinet_id INT NULL REFERENCES cabinets(cabinet_id),
    connected_device_id INT NULL REFERENCES devices(device_id),
    notes NVARCHAR(255) NULL,
    CONSTRAINT UQ_pdu_breaker_num UNIQUE (pdu_id, breaker_number)
);
GO

CREATE NONCLUSTERED INDEX IX_pdu_breakers_pdu ON pdu_breakers(pdu_id, slot_start);
GO

-- Device power supplies (PSU line items) mapped to PDU outlets
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'device_power_supplies')
CREATE TABLE device_power_supplies (
    power_supply_id INT IDENTITY(1,1) PRIMARY KEY,
    device_id INT NOT NULL REFERENCES devices(device_id) ON DELETE CASCADE,
    name NVARCHAR(100) NOT NULL DEFAULT 'PSU',
    watts DECIMAL(10,2) NULL,
    connector_type NVARCHAR(50) NULL, -- NEMA / IEC plug on device
    pdu_id INT NULL REFERENCES pdus(pdu_id),
    pdu_outlet_id INT NULL REFERENCES pdu_outlets(outlet_id),
    sort_order INT NOT NULL DEFAULT 0,
    notes NVARCHAR(255) NULL
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'pdu_readings')
CREATE TABLE pdu_readings (
    reading_id BIGINT IDENTITY(1,1) PRIMARY KEY,
    pdu_id INT NOT NULL REFERENCES pdus(pdu_id) ON DELETE CASCADE,
    watts DECIMAL(12,2) NULL,
    amps DECIMAL(10,2) NULL,
    volts DECIMAL(8,2) NULL,
    kwh DECIMAL(14,4) NULL,
    temperature_c DECIMAL(6,2) NULL,
    humidity_pct DECIMAL(5,2) NULL,
    raw_payload NVARCHAR(MAX) NULL,
    polled_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

CREATE NONCLUSTERED INDEX IX_pdu_readings_pdu_time ON pdu_readings(pdu_id, polled_at DESC);
GO

-- ============================================================
-- SNMP device polling (generic sensors)
-- ============================================================

-- Reusable SNMPv3 credential profiles (applied to devices / targets)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'snmp_v3_profiles')
CREATE TABLE snmp_v3_profiles (
    profile_id INT IDENTITY(1,1) PRIMARY KEY,
    name NVARCHAR(100) NOT NULL UNIQUE,
    security_name NVARCHAR(100) NOT NULL, -- SNMPv3 username
    security_level NVARCHAR(30) NOT NULL DEFAULT 'authPriv', -- noAuthNoPriv, authNoPriv, authPriv
    auth_protocol NVARCHAR(20) NULL, -- MD5, SHA, SHA224, SHA256, SHA384, SHA512
    auth_passphrase NVARCHAR(255) NULL,
    priv_protocol NVARCHAR(20) NULL, -- DES, AES, AES192, AES256
    priv_passphrase NVARCHAR(255) NULL,
    context_name NVARCHAR(100) NULL,
    notes NVARCHAR(500) NULL,
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

-- Site-discovered OID templates (Vendor+Model) shared across devices of the same model
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'snmp_site_oid_templates')
CREATE TABLE snmp_site_oid_templates (
    template_id INT IDENTITY(1,1) PRIMARY KEY,
    name NVARCHAR(150) NOT NULL, -- Vendor+Model
    vendor NVARCHAR(100) NULL,
    model NVARCHAR(100) NULL,
    oid_map NVARCHAR(MAX) NOT NULL DEFAULT '{}', -- JSON: {metric: oid}
    source NVARCHAR(30) NOT NULL DEFAULT 'discovered', -- discovered, manual
    notes NVARCHAR(500) NULL,
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'snmp_targets')
CREATE TABLE snmp_targets (
    target_id INT IDENTITY(1,1) PRIMARY KEY,
    name NVARCHAR(100) NOT NULL,
    device_id INT NULL REFERENCES devices(device_id),
    pdu_id INT NULL REFERENCES pdus(pdu_id),
    host NVARCHAR(255) NOT NULL,
    port INT NOT NULL DEFAULT 161,
    snmp_version NVARCHAR(10) NOT NULL DEFAULT '3',
    security_name NVARCHAR(100) NULL,
    auth_protocol NVARCHAR(20) NULL,
    auth_passphrase NVARCHAR(255) NULL,
    priv_protocol NVARCHAR(20) NULL,
    priv_passphrase NVARCHAR(255) NULL,
    context_name NVARCHAR(100) NULL,
    poll_interval_sec INT NOT NULL DEFAULT 300,
    oid_map NVARCHAR(MAX) NULL, -- JSON: {metric: oid}
    site_template_id INT NULL, -- optional shared map from snmp_site_oid_templates
    is_enabled BIT NOT NULL DEFAULT 1,
    last_success_at DATETIME2 NULL,
    last_error NVARCHAR(500) NULL,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'snmp_readings')
CREATE TABLE snmp_readings (
    reading_id BIGINT IDENTITY(1,1) PRIMARY KEY,
    target_id INT NOT NULL REFERENCES snmp_targets(target_id) ON DELETE CASCADE,
    metric_name NVARCHAR(100) NOT NULL,
    metric_value DECIMAL(18,6) NULL,
    metric_text NVARCHAR(255) NULL,
    polled_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

CREATE NONCLUSTERED INDEX IX_snmp_readings_target ON snmp_readings(target_id, polled_at DESC);
GO

-- ============================================================
-- Disposal / Lifecycle
-- ============================================================

-- ITAD / recycle / destruction vendors
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'disposal_vendors')
CREATE TABLE disposal_vendors (
    vendor_id INT IDENTITY(1,1) PRIMARY KEY,
    name NVARCHAR(150) NOT NULL,
    vendor_type NVARCHAR(40) NOT NULL DEFAULT 'itad', -- itad, recycle, destroy, resale, donate
    contact_name NVARCHAR(150) NULL,
    contact_email NVARCHAR(255) NULL,
    contact_phone NVARCHAR(50) NULL,
    website NVARCHAR(255) NULL,
    certifications NVARCHAR(255) NULL, -- R2, e-Stewards, NAID AAA, etc.
    address NVARCHAR(500) NULL,
    notes NVARCHAR(MAX) NULL,
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'disposals')
CREATE TABLE disposals (
    disposal_id INT IDENTITY(1,1) PRIMARY KEY,
    device_id INT NOT NULL REFERENCES devices(device_id),
    requested_by INT NULL REFERENCES users(user_id),
    approved_by INT NULL REFERENCES users(user_id),
    status NVARCHAR(30) NOT NULL DEFAULT 'pending',
    -- pending, approved, in_progress, completed, cancelled
    -- Workflow stage (NIST-aligned decommission process)
    stage NVARCHAR(40) NOT NULL DEFAULT 'planning',
    -- planning, sanitization, verification, disposition, post_review, closed
    reason NVARCHAR(500) NULL,
    method NVARCHAR(50) NULL, -- recycle, destroy, return_lease, donate, resale
    -- 1) Inventory & planning
    change_ticket NVARCHAR(100) NULL,
    data_sensitivity NVARCHAR(30) NULL, -- low, medium, high, critical
    workload_migration NVARCHAR(MAX) NULL,
    asset_verified BIT NOT NULL DEFAULT 0,
    planning_notes NVARCHAR(MAX) NULL,
    planning_completed_at DATETIME2 NULL,
    -- 2) Data sanitization (NIST 800-88)
    sanitize_category NVARCHAR(20) NULL, -- Clear, Purge, Destroy
    sanitize_method NVARCHAR(100) NULL, -- e.g. crypto-erase, degauss, shred, factory+reimage
    sanitize_on_site BIT NULL,
    network_config_cleared BIT NOT NULL DEFAULT 0,
    credentials_cleared BIT NOT NULL DEFAULT 0,
    logs_cleared BIT NOT NULL DEFAULT 0,
    sanitize_details NVARCHAR(MAX) NULL,
    sanitize_performed_by NVARCHAR(150) NULL,
    sanitize_performed_at DATETIME2 NULL,
    -- 3) Verification & documentation
    certificate_no NVARCHAR(100) NULL, -- certificate of sanitization / destruction
    chain_of_custody NVARCHAR(150) NULL,
    verification_notes NVARCHAR(MAX) NULL,
    verified_by NVARCHAR(150) NULL,
    verified_at DATETIME2 NULL,
    -- 4) Physical disposition
    vendor_id INT NULL REFERENCES disposal_vendors(vendor_id),
    disposition_ref NVARCHAR(100) NULL, -- BOL / pickup ref
    pickup_date DATE NULL,
    scheduled_date DATE NULL,
    completed_date DATE NULL,
    -- 5) Post-review
    lessons_learned NVARCHAR(MAX) NULL,
    policy_updates NVARCHAR(MAX) NULL,
    post_review_at DATETIME2 NULL,
    post_review_by NVARCHAR(150) NULL,
    notification_sent BIT NOT NULL DEFAULT 0,
    notification_sent_at DATETIME2 NULL,
    notes NVARCHAR(MAX) NULL,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'notifications')
CREATE TABLE notifications (
    notification_id BIGINT IDENTITY(1,1) PRIMARY KEY,
    user_id INT NULL REFERENCES users(user_id),
    title NVARCHAR(200) NOT NULL,
    message NVARCHAR(MAX) NOT NULL,
    category NVARCHAR(50) NOT NULL DEFAULT 'info', -- info, warning, disposal, audit, system
    entity_type NVARCHAR(50) NULL,
    entity_id INT NULL,
    is_read BIT NOT NULL DEFAULT 0,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

-- ============================================================
-- Reports & Audits
-- ============================================================

-- Per-cabinet physical audit certifications (walkthrough sign-off)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'cabinet_audits')
CREATE TABLE cabinet_audits (
    cabinet_audit_id INT IDENTITY(1,1) PRIMARY KEY,
    cabinet_id INT NOT NULL REFERENCES cabinets(cabinet_id),
    audited_by INT NULL REFERENCES users(user_id),
    audited_by_name NVARCHAR(150) NULL,
    certified BIT NOT NULL DEFAULT 1,
    comments NVARCHAR(MAX) NULL,
    audited_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO
CREATE NONCLUSTERED INDEX IX_cabinet_audits_cabinet ON cabinet_audits(cabinet_id, audited_at DESC);
GO
CREATE NONCLUSTERED INDEX IX_cabinet_audits_user ON cabinet_audits(audited_by, audited_at DESC);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'audit_jobs')
CREATE TABLE audit_jobs (
    job_id INT IDENTITY(1,1) PRIMARY KEY,
    name NVARCHAR(150) NOT NULL,
    audit_type NVARCHAR(50) NOT NULL, -- cabinet, inventory, power, cable, full
    scope_type NVARCHAR(50) NULL, -- datacenter, room, cabinet
    scope_id INT NULL,
    assigned_to INT NULL REFERENCES users(user_id),
    status NVARCHAR(30) NOT NULL DEFAULT 'open', -- open, in_progress, completed, cancelled
    due_date DATE NULL,
    started_at DATETIME2 NULL,
    completed_at DATETIME2 NULL,
    findings_summary NVARCHAR(MAX) NULL,
    created_by INT NULL REFERENCES users(user_id),
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'audit_items')
CREATE TABLE audit_items (
    item_id INT IDENTITY(1,1) PRIMARY KEY,
    job_id INT NOT NULL REFERENCES audit_jobs(job_id) ON DELETE CASCADE,
    entity_type NVARCHAR(50) NOT NULL,
    entity_id INT NOT NULL,
    expected_value NVARCHAR(500) NULL,
    actual_value NVARCHAR(500) NULL,
    result NVARCHAR(30) NOT NULL DEFAULT 'pending', -- pending, match, mismatch, missing, extra
    notes NVARCHAR(MAX) NULL,
    checked_by INT NULL REFERENCES users(user_id),
    checked_at DATETIME2 NULL
);
GO

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'report_definitions')
CREATE TABLE report_definitions (
    report_id INT IDENTITY(1,1) PRIMARY KEY,
    name NVARCHAR(150) NOT NULL,
    report_key NVARCHAR(50) NOT NULL UNIQUE,
    description NVARCHAR(500) NULL,
    category NVARCHAR(50) NOT NULL DEFAULT 'inventory',
    is_system BIT NOT NULL DEFAULT 1
);
GO

-- ============================================================
-- Escalations / Work Requests (openDCIM parity)
-- ============================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'rack_requests')
CREATE TABLE rack_requests (
    request_id INT IDENTITY(1,1) PRIMARY KEY,
    requested_by INT NULL REFERENCES users(user_id),
    department_id INT NULL REFERENCES departments(department_id),
    cabinet_id INT NULL REFERENCES cabinets(cabinet_id),
    title NVARCHAR(200) NOT NULL,
    description NVARCHAR(MAX) NULL,
    request_type NVARCHAR(50) NOT NULL DEFAULT 'install', -- install, move, decommission, power, network
    status NVARCHAR(30) NOT NULL DEFAULT 'submitted',
    -- submitted, approved, scheduled, completed, rejected
    priority NVARCHAR(20) NOT NULL DEFAULT 'normal',
    desired_date DATE NULL,
    completed_at DATETIME2 NULL,
    notes NVARCHAR(MAX) NULL,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
GO

-- ============================================================
-- Seed system roles & reports
-- ============================================================

-- Platform roles (permissions refreshed by Schema::ensureRoles on boot)
IF NOT EXISTS (SELECT 1 FROM roles WHERE name = 'Administrator')
INSERT INTO roles (name, description, permissions, is_system) VALUES
('Administrator', 'Full platform access (legacy name for Global Admin)', '["*"]', 1);
GO
IF NOT EXISTS (SELECT 1 FROM roles WHERE name = 'Global Admin')
INSERT INTO roles (name, description, permissions, is_system) VALUES
('Global Admin', 'Full platform access including site settings and user administration', '["*"]', 1);
GO
IF NOT EXISTS (SELECT 1 FROM roles WHERE name = 'Data Center Admin')
INSERT INTO roles (name, description, permissions, is_system) VALUES
('Data Center Admin', 'View all; modify zones, rows, cabinets, devices, power, cabling, lifecycle',
 '["view_dashboard","view_floorplan","view_datacenters","view_cabinets","view_devices","view_power","view_cables","view_snmp","view_disposals","view_audits","view_reports","view_notifications","edit_devices_all","edit_infrastructure","edit_power","edit_cables","edit_templates","edit_disposals","edit_audits","edit_snmp"]', 1);
GO
IF NOT EXISTS (SELECT 1 FROM roles WHERE name = 'Department Admin')
INSERT INTO roles (name, description, permissions, is_system) VALUES
('Department Admin', 'View all; fully modify devices (and decommission) in their department',
 '["view_dashboard","view_floorplan","view_datacenters","view_cabinets","view_devices","view_power","view_cables","view_snmp","view_disposals","view_audits","view_reports","view_notifications","edit_devices_dept","edit_disposals"]', 1);
GO
IF NOT EXISTS (SELECT 1 FROM roles WHERE name = 'Viewer')
INSERT INTO roles (name, description, permissions, is_system) VALUES
('Viewer', 'General view-only — inventory, power, reports (no changes)',
 '["view_dashboard","view_floorplan","view_datacenters","view_cabinets","view_devices","view_power","view_cables","view_snmp","view_disposals","view_audits","view_reports","view_notifications"]', 1);
GO

-- Map AD / Entra security groups → roles (applied at SSO login later)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'role_group_maps')
CREATE TABLE role_group_maps (
    map_id INT IDENTITY(1,1) PRIMARY KEY,
    role_id INT NOT NULL REFERENCES roles(role_id) ON DELETE CASCADE,
    auth_source NVARCHAR(20) NOT NULL, -- ldaps, entra
    group_id NVARCHAR(255) NOT NULL,
    group_name NVARCHAR(255) NULL,
    is_active BIT NOT NULL DEFAULT 1,
    notes NVARCHAR(255) NULL,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    CONSTRAINT UQ_role_group_map UNIQUE (auth_source, group_id)
);
GO

IF NOT EXISTS (SELECT 1 FROM report_definitions WHERE report_key = 'inventory_summary')
INSERT INTO report_definitions (name, report_key, description, category) VALUES
('Inventory Summary', 'inventory_summary', 'Devices by type, status, and datacenter', 'inventory'),
('Cabinet Utilization', 'cabinet_utilization', 'U-space used vs available per cabinet', 'capacity'),
('Power Capacity', 'power_capacity', 'Power zone and PDU utilization', 'power'),
('Warranty Expiration', 'warranty_expiration', 'Devices with warranty ending soon', 'lifecycle'),
('Disposal Queue', 'disposal_queue', 'Pending and in-progress disposals', 'lifecycle'),
('Cable Inventory', 'cable_inventory', 'Cables and port connections', 'network'),
('Audit History', 'audit_history', 'Completed audit jobs and findings', 'compliance'),
('Orphaned Devices', 'orphaned_devices', 'Devices not assigned to a cabinet', 'inventory');
GO

IF NOT EXISTS (SELECT 1 FROM settings WHERE setting_key = 'app_name')
INSERT INTO settings (setting_key, setting_value, category) VALUES
('app_name', 'ColdAisle', 'general'),
('app_version', '1.0.0', 'general'),
('default_u_height', '42', 'cabinets'),
('default_cabinet_width_mm', '600', 'cabinets'),
('default_cabinet_depth_mm', '1200', 'cabinets'),
('audit_interval_days', '90', 'compliance'),
('disposal_notify_days', '7', 'lifecycle'),
('snmp_poll_enabled', '0', 'snmp'),
('auth_local_enabled', '1', 'auth'),
('auth_ldaps_enabled', '0', 'auth'),
('auth_entra_enabled', '0', 'auth');
GO
