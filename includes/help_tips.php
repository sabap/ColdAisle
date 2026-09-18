<?php
/**
 * Page help tips — circled “?” in the top bar opens a slide-out drawer.
 *
 * Bodies are trusted operator copy (not user input).
 *
 * @return array<string,array{title:string,html:string}>
 */
function layout_help_catalog(): array
{
    $docs = class_exists('App') ? App::url('pages/docs.php') : 'pages/docs.php';
    return [
        'dash_lab' => [
            'title' => 'Hall lab',
            'html' => '<p>Experimental overlay hall: large 3D, glass metric chips, icon rail, and a side inspector. Production dashboard and NOC are unchanged.</p>'
                . '<ul><li>Rail icons switch Overview / Thermal / Power / Inventory chips.</li>'
                . '<li>Orbit / Aisle / Plan are camera presets. Aisle uses walk mode.</li>'
                . '<li><strong>Lab NOC</strong> is the same layout as a wall display (same token as production NOC).</li></ul>',
        ],
        'dashboard' => [
            'title' => 'Dashboard',
            'html' => '<p>Live snapshot of the hall: inventory counts, polled load, UPS, cooling, and the 3D view.</p>'
                . '<ul><li><strong>Edit floor plan</strong> on the 3D card opens the planner (Hall → Floor planner).</li>'
                . '<li>Cards link through to the matching list page.</li></ul>',
        ],
        'hall' => [
            'title' => 'Hall',
            'html' => '<p>Sites, rooms, cabinets, and devices. Place racks on the floor after rooms exist.</p>'
                . '<p>Floor planner is under this section — it is setup for the hall, not a daily ops list.</p>',
        ],
        'datacenters' => [
            'title' => 'Data Centers',
            'html' => '<p>Hierarchy: Site → Data center → Room. Room size is stored in meters and drives the white floor in the planner.</p>'
                . '<p>Use <strong>Floor plan</strong> on a room row (or Hall → Floor planner) to place cabinets.</p>',
        ],
        'cabinets' => [
            'title' => 'Cabinets',
            'html' => '<p>Every rack: U height, row, power, and elevation. Open a cabinet for front/rear U slots and devices.</p>'
                . '<p>Place or nudge racks on the floor planner; this list is inventory.</p>',
        ],
        'devices' => [
            'title' => 'Devices',
            'html' => '<p>IT assets in U slots: servers, switches, storage, chassis children.</p>'
                . '<ul><li>Templates are the catalog (vendor/model/U/ports) — create once, add many.</li>'
                . '<li>Connect ports for cabling. Dell uses the iDRAC host for SNMP.</li></ul>',
        ],
        'device_templates' => [
            'title' => 'Device templates',
            'html' => '<p>Vendor/model catalog: U height, pictures, default ports. New devices inherit the template so elevations and 3D stay consistent.</p>',
        ],
        'floorplan' => [
            'title' => 'Floor planner',
            'html' => '<p>2D canvas to place cabinets, floor PDUs, cooling, UPS, vents/returns, and raceways. 3D is the same hall for clearances.</p>'
                . '<ul><li>Blue edge is the front of the rack (cold aisle).</li>'
                . '<li>Room size comes from Data Centers → Room (meters).</li>'
                . '<li>Raceways: draw on 2D, Finish/Enter to name. Clone U-channel copies a ladder at a higher elevation.</li></ul>'
                . '<p><a href="' . htmlspecialchars($docs, ENT_QUOTES, 'UTF-8') . '#floorplan">Documentation → Floor planner</a></p>',
        ],
        'power' => [
            'title' => 'Power',
            'html' => '<p>Facility and rack load after SNMP is on. Zones → panels → circuits → PDUs is the electrical tree.</p>'
                . '<ul><li>Rack and floor PDUs: outlets, templates, poll.</li>'
                . '<li>UPS: load %, battery, runtime. Place a footprint on the planner if you want it in 3D.</li></ul>',
        ],
        'cooling' => [
            'title' => 'Cooling',
            'html' => '<p>CRAH/CRAC inventory, env sensors, and live telemetry (Liebert DS over SNMPv3).</p>'
                . '<ul><li>Set sensor <strong>Placement</strong> to Cold aisle or Hot aisle for 3D rainbow and NOC graphs.</li>'
                . '<li>SNMP On/Off/Standby drives the 3D active/standby LED.</li></ul>',
        ],
        'cables' => [
            'title' => 'Cabling',
            'html' => '<p>Port-to-port circuits and ordered raceway hops. Draw raceways on the floor planner first, then Path / Calc path from a cable.</p>',
        ],
        'ipam' => [
            'title' => 'IPAM',
            'html' => '<p>Prefixes and host records — not DNS, DHCP, or Infoblox. DHCP start/end on an address plan is only a fence for leases.</p>'
                . '<h3>Three tools</h3>'
                . '<ol><li><strong>Address plan</strong> — named hosts in a prefix (VLAN /24, WAN /24, iDRAC /24). Empty IPs are not stored; Next free is computed.</li>'
                . '<li><strong>Subnet plan</strong> — a container you carve into smaller prefixes. Set Parent on children. Import does not invent the tree.</li>'
                . '<li><strong>Aligned group</strong> — same host <em>index</em> across two or more address-plan prefixes (Metro-E last octet, or LAN + iDRAC). Not a parent/child tree.</li></ol>'
                . '<p>Index is the offset from each network address. On a /24 that is the last octet: index 10 on 10.10.0.0/24, 10.10.1.0/24 is .10 on each.</p>'
                . '<p><a href="' . htmlspecialchars($docs, ENT_QUOTES, 'UTF-8') . '#ipam">Documentation → IPAM</a> has the assign walkthrough.</p>',
        ],
        'snmp' => [
            'title' => 'SNMP',
            'html' => '<p>Discover, site templates, and poll. Discover lives on the unit (device, PDU, UPS, cooling) — not a separate wizard.</p>'
                . '<p>Scheduled poll is a Windows task (<code>run_poll_snmp.cmd</code>). Settings → SNMP schedule sets the interval. Web Discover needs PHP snmp enabled.</p>'
                . '<p><a href="' . htmlspecialchars($docs, ENT_QUOTES, 'UTF-8') . '#snmp-discover">Documentation → SNMP Discover</a></p>',
        ],
        'work_orders' => [
            'title' => 'Work orders',
            'html' => '<p>Install, move, and change tickets tied to cabinets and U positions. Apply to inventory when the window is done so the elevation is not edited twice.</p>'
                . '<p><a href="' . htmlspecialchars($docs, ENT_QUOTES, 'UTF-8') . '#work-orders">Documentation → Work-order apply</a></p>',
        ],
        'disposals' => [
            'title' => 'Decommission',
            'html' => '<p>NIST-style dispose flow: plan → sanitize → verify → done. Completing a disposal frees the U slot for the next install.</p>',
        ],
        'audits' => [
            'title' => 'Audits',
            'html' => '<p>Walk a rack, check what is actually there, record the result. Dashboard compliance % comes from these jobs and due dates.</p>',
        ],
        'reports' => [
            'title' => 'Reports',
            'html' => '<p>Capacity, power, inventory, warranty. Use reports for a printable or exportable slice instead of clicking through pages.</p>',
        ],
        'users' => [
            'title' => 'Users &amp; departments',
            'html' => '<p>Local accounts, platform roles, and departments. Directory users appear after a successful LDAPS or Entra login.</p>'
                . '<p>View / Edit per area is on Users → Platform roles. Global Admins mint API service accounts here.</p>',
        ],
        'docs' => [
            'title' => 'Documentation',
            'html' => '<p>Operator how-tos and the machine API. The circled ? on other pages is the short version of the same topics.</p>',
        ],
        'settings' => [
            'title' => 'Settings',
            'html' => '<p>Org, security, LDAP, mail, alerts, SNMP schedule, backups, updates, setup wizard, and the site tour.</p>'
                . '<p>If something “won’t stick,” it is usually here — or IIS app-pool write on <code>config/</code> and <code>storage/</code>.</p>',
        ],
        'notifications' => [
            'title' => 'Notifications',
            'html' => '<p>In-app alerts: power, environment, ICMP, warranty, system. Email is optional under Settings.</p>',
        ],
    ];
}

/**
 * Help topic id for a layout $active key, or null if none.
 */
function layout_help_id_for_active(string $active): ?string
{
    $map = [
        'dashboard' => 'dashboard',
        'dash_lab' => 'dash_lab',
        'floorplan' => 'floorplan',
        'datacenters' => 'datacenters',
        'cabinets' => 'cabinets',
        'devices' => 'devices',
        'device_templates' => 'device_templates',
        'power' => 'power',
        'power_zones' => 'power',
        'power_pdus' => 'power',
        'power_pdu_templates' => 'power',
        'power_templates' => 'power',
        'power_ups' => 'power',
        'cooling' => 'cooling',
        'cooling_units' => 'cooling',
        'env_sensors' => 'cooling',
        'cables' => 'cables',
        'ipam' => 'ipam',
        'snmp' => 'snmp',
        'work_orders' => 'work_orders',
        'disposals' => 'disposals',
        'audits' => 'audits',
        'reports' => 'reports',
        'users' => 'users',
        'docs' => 'docs',
        'settings' => 'settings',
        'notifications' => 'notifications',
    ];
    $id = $map[$active] ?? null;
    if ($id === null) {
        return null;
    }
    $cat = layout_help_catalog();
    return isset($cat[$id]) ? $id : null;
}
