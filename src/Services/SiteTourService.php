<?php
/**
 * Interactive site walkthrough — flag markers on real UI, multi-page, exit anytime.
 */
declare(strict_types=1);

class SiteTourService
{
    public const SETTING_KEY = 'site_tour_state';

    public const STATUS_IDLE = 'idle';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXITED = 'exited';

    /**
     * @return list<array{
     *   id:string,title:string,body:string,page:string,target:?string,
     *   nav:?string,place?:string
     * }>
     */
    public static function catalog(): array
    {
        $steps = [
            [
                'id' => 'welcome',
                'title' => 'Welcome to ColdAisle',
                'body' => 'This short tour points at the real screens — not screenshots. Use Next / Back, or Exit anytime. You can restart it from Settings whenever you want.',
                'page' => 'index.php',
                'target' => null,
                'nav' => 'dashboard',
                'place' => 'center',
            ],
            [
                'id' => 'sidebar',
                'title' => 'The sidebar is home base',
                'body' => 'Every major area of the product lives here. The highlighted item is the page you are on. Collapse the bar with the ☰ button if you need more canvas.',
                'page' => 'index.php',
                'target' => '[data-tour="sidebar"]',
                'nav' => 'dashboard',
                'place' => 'right',
            ],
            [
                'id' => 'dash-metrics',
                'title' => 'Dashboard pulse',
                'body' => 'These cards are a live snapshot: cabinets, devices, U fill, polled kW, UPS health, open decommissions, and audit compliance. Click a card’s link when you want the detail page.',
                'page' => 'index.php',
                'target' => '[data-tour="dash-metrics"]',
                'nav' => 'dashboard',
                'place' => 'bottom',
            ],
            [
                'id' => 'dash-3d',
                'title' => 'Site in 3D',
                'body' => 'The dashboard hall view is the same geometry as the floor planner. Drag to orbit, or turn on Walk hall and move with WASD / arrow keys (mouse-drag looks around). Heat overlay (when enabled) tints racks from environment or power load so hot spots show up without opening each cabinet.',
                'page' => 'index.php',
                'target' => '[data-tour="dash-3d"]',
                'nav' => 'dashboard',
                'place' => 'top',
            ],
            [
                'id' => 'tech-mode',
                'title' => 'Desktop vs Tech',
                'body' => 'Desktop is the full planning UI. Tech mode is a field-friendly shell (bigger tap targets, fewer chrome bits) for rack work on a tablet or cart. Same data — switch anytime.',
                'page' => 'index.php',
                'target' => '[data-tour="tech-mode"]',
                'nav' => 'dashboard',
                'place' => 'bottom',
            ],
            [
                'id' => 'notifications',
                'title' => 'Alerts land here',
                'body' => 'The bell is in-app notifications: power, environment, ICMP, warranty, and system events. Unread count stays on the badge. Email is optional and configured under Settings.',
                'page' => 'index.php',
                'target' => '[data-tour="notifications"]',
                'nav' => 'dashboard',
                'place' => 'bottom',
            ],
            [
                'id' => 'nav-floorplan',
                'title' => 'Floor Planner',
                'body' => 'Start spatial work here. The planner is the canvas for cabinets, floor PDUs, cooling, UPS footprints, and cable raceways — 2D to draw, 3D to check clearances.',
                'page' => 'index.php',
                'target' => '[data-tour="nav-floorplan"]',
                'nav' => 'dashboard',
                'place' => 'right',
            ],
            [
                'id' => 'fp-room',
                'title' => 'Pick the hall',
                'body' => 'A site can have several rooms. This list switches the canvas. Room size (always stored in meters) is edited under Edit Room / North — that is the white-space rectangle you see.',
                'page' => 'pages/floorplan.php',
                'target' => '[data-tour="fp-room"]',
                'nav' => 'floorplan',
                'place' => 'bottom',
            ],
            [
                'id' => 'fp-add',
                'title' => 'Place cabinets',
                'body' => '+ Cabinet or drag a model from the left palette onto the floor. Then drag to move, use nudge for fine shifts, and snap-to-grid so aisles stay straight. Blue edge is the front of the rack.',
                'page' => 'pages/floorplan.php',
                'target' => '[data-tour="fp-add"]',
                'nav' => 'floorplan',
                'place' => 'bottom',
            ],
            [
                'id' => 'fp-grid',
                'title' => 'Grid, snap, and units',
                'body' => 'Toggle meters vs feet for labels. Grid and Snap keep 1 ft (or 0.3 m) tiles honest. Zoom with the +/− buttons or the scroll wheel. None of this changes stored meters — only how you work.',
                'page' => 'pages/floorplan.php',
                'target' => '[data-tour="fp-grid"]',
                'nav' => 'floorplan',
                'place' => 'bottom',
            ],
            [
                'id' => 'fp-3d',
                'title' => '3D and raceways',
                'body' => '3D View is the same hall in elevation. Walk puts you in the aisle (WASD / arrows, drag to look; Esc back to orbit). Raceways: On draws ladder / U-channel / trough. Draw raceway to click a path; Finish to save a code. Clone U-channel copies a ladder route at a higher elevation.',
                'page' => 'pages/floorplan.php',
                'target' => '[data-tour="fp-3d"]',
                'nav' => 'floorplan',
                'place' => 'bottom',
            ],
            [
                'id' => 'nav-datacenters',
                'title' => 'Sites and halls',
                'body' => 'Data Centers holds the hierarchy: Site → DC → Room. Names, codes, and addresses live here. The planner uses the room; this page is the inventory of those rooms.',
                'page' => 'pages/datacenters.php',
                'target' => '[data-tour="nav-datacenters"]',
                'nav' => 'datacenters',
                'place' => 'right',
            ],
            [
                'id' => 'nav-cabinets',
                'title' => 'Cabinet list',
                'body' => 'A table of every rack: U height, row, location tag, power. Open a cabinet for the elevation (front/rear), health, and the devices in each U. Use this when the floor plan is too zoomed out.',
                'page' => 'pages/cabinets.php',
                'target' => '[data-tour="nav-cabinets"]',
                'nav' => 'cabinets',
                'place' => 'right',
            ],
            [
                'id' => 'nav-devices',
                'title' => 'Devices',
                'body' => 'All IT assets: servers, switches, storage, chassis. Filter by cabinet or type. Open a device to set U position, ports, power supplies, SNMP, and Connect (cabling to a peer).',
                'page' => 'pages/devices.php',
                'target' => '[data-tour="nav-devices"]',
                'nav' => 'devices',
                'place' => 'right',
            ],
            [
                'id' => 'nav-device-templates',
                'title' => 'Device templates',
                'body' => 'Templates are the catalog: vendor, model, U height, pictures, default ports. Create a template once, then add many devices from it so elevations and 3D stay consistent.',
                'page' => 'pages/devices.php',
                'target' => '[data-tour="nav-device-templates"]',
                'nav' => 'devices',
                'place' => 'right',
            ],
            [
                'id' => 'nav-power',
                'title' => 'Power dashboard',
                'body' => 'Facility and rack load, history charts, and UPS roll-up. This is where you watch watts over time after SNMP polling is on. Zones and PDUs hang off this menu.',
                'page' => 'pages/power.php',
                'target' => '[data-tour="nav-power"]',
                'nav' => 'power',
                'place' => 'right',
            ],
            [
                'id' => 'nav-power-zones',
                'title' => 'Power path',
                'body' => 'Zones → panels → circuits → PDUs. Model the electrical tree so a rack PDU knows its upstream. That is what site-load and “which breaker?” questions use.',
                'page' => 'pages/power_zones.php',
                'target' => '[data-tour="nav-power-zones"]',
                'nav' => 'power',
                'place' => 'right',
            ],
            [
                'id' => 'nav-power-pdus',
                'title' => 'PDUs',
                'body' => 'Rack and floor PDUs: name, IP, template, SNMP. Poll for amps/watts, map outlets to devices, and include (or exclude) a PDU from site load. Floor PDUs can sit on the planner like a cabinet.',
                'page' => 'pages/power_pdus.php',
                'target' => '[data-tour="nav-power-pdus"]',
                'nav' => 'power',
                'place' => 'right',
            ],
            [
                'id' => 'nav-power-ups',
                'title' => 'UPS',
                'body' => 'UPS inventory and last poll: load %, battery %, runtime, on-battery. Place a UPS footprint on the floor plan if you want it in 3D next to the CRACs.',
                'page' => 'pages/power_ups.php',
                'target' => '[data-tour="nav-power-ups"]',
                'nav' => 'power',
                'place' => 'right',
            ],
            [
                'id' => 'nav-cooling',
                'title' => 'Cooling',
                'body' => 'CRAH/CRAC and pumps, plus environment sensors. The cooling dashboard and 3D tiles use the same units you set for temperature. Sensors can hang off a PDU or a dedicated probe.',
                'page' => 'pages/cooling.php',
                'target' => '[data-tour="nav-cooling"]',
                'nav' => 'cooling',
                'place' => 'right',
            ],
            [
                'id' => 'nav-cables',
                'title' => 'Cabling',
                'body' => 'Port-to-port cables and multi-hop raceway routes. From a device you can Connect two ports and pick ordered paths (ladder → U-channel → ladder). This page is the plant-wide list.',
                'page' => 'pages/cables.php',
                'target' => '[data-tour="nav-cables"]',
                'nav' => 'cables',
                'place' => 'right',
            ],
            [
                'id' => 'nav-snmp',
                'title' => 'SNMP',
                'body' => 'Discover, templates, and per-device poll. After a device or PDU has an IP, Discover maps OIDs. Settings → SNMP schedule is the Windows task that keeps history charts full.',
                'page' => 'pages/snmp.php',
                'target' => '[data-tour="nav-snmp"]',
                'nav' => 'snmp',
                'place' => 'right',
            ],
            [
                'id' => 'nav-work-orders',
                'title' => 'Work orders',
                'body' => 'Install, move, and change tickets tied to cabinets and U positions. Use these when several people share a change window so the elevation does not get edited twice.',
                'page' => 'pages/work_orders.php',
                'target' => '[data-tour="nav-work-orders"]',
                'nav' => 'work_orders',
                'place' => 'right',
            ],
            [
                'id' => 'nav-disposals',
                'title' => 'Decommission',
                'body' => 'NIST-style dispose flow: plan → sanitize → verify → done. Completing a disposal removes the device from the rack so U space frees up for the next install.',
                'page' => 'pages/disposals.php',
                'target' => '[data-tour="nav-disposals"]',
                'nav' => 'disposals',
                'place' => 'right',
            ],
            [
                'id' => 'nav-audits',
                'title' => 'Audits',
                'body' => 'Cabinet audits: walk a rack, check what is actually there, and record the result. Compliance % on the dashboard comes from these jobs and their due dates.',
                'page' => 'pages/audits.php',
                'target' => '[data-tour="nav-audits"]',
                'nav' => 'audits',
                'place' => 'right',
            ],
            [
                'id' => 'nav-reports',
                'title' => 'Reports',
                'body' => 'Saved and ad-hoc views: capacity, power, inventory. Use reports when you need a printable or exportable slice instead of clicking through pages.',
                'page' => 'pages/reports.php',
                'target' => '[data-tour="nav-reports"]',
                'nav' => 'reports',
                'place' => 'right',
            ],
            [
                'id' => 'nav-users',
                'title' => 'Users and departments',
                'body' => 'Local accounts, roles (Viewer through Global Admin), and departments. Directory users appear after a successful LDAPS/Entra login. Permissions hide nav items you should not see. Global Admins mint API-service accounts here; Documentation has the machine API reference.',
                'page' => 'pages/users.php',
                'target' => '[data-tour="nav-users"]',
                'nav' => 'users',
                'place' => 'right',
            ],
            [
                'id' => 'nav-settings',
                'title' => 'Settings',
                'body' => 'Org, security, LDAP, mail, alerts, SNMP schedule, backups, updates, the setup wizard, and this tour. If something “won’t stick,” it is usually here — or the IIS app-pool permissions.',
                'page' => 'pages/settings.php',
                'target' => '[data-tour="nav-settings"]',
                'nav' => 'settings',
                'place' => 'right',
            ],
            [
                'id' => 'settings-help',
                'title' => 'Come back anytime',
                'body' => 'Setup wizard (first-time config) and this tour both live in Settings. Exit never deletes data. Restart the tour from this card whenever you onboard someone new.',
                'page' => 'pages/settings.php',
                'target' => '[data-tour="settings-help"]',
                'nav' => 'settings',
                'place' => 'bottom',
            ],
            [
                'id' => 'done',
                'title' => 'You are ready',
                'body' => 'Typical first week: place cabinets on the floor plan, add devices from templates, hang PDUs, then enable SNMP if you want live charts. The dashboard will start to mean something once those pieces exist.',
                'page' => 'index.php',
                'target' => null,
                'nav' => 'dashboard',
                'place' => 'center',
            ],
        ];

        $chapterOf = [
            'welcome' => 'Start',
            'sidebar' => 'Start',
            'dash-metrics' => 'Start',
            'dash-3d' => 'Start',
            'tech-mode' => 'Start',
            'notifications' => 'Start',
            'nav-floorplan' => 'Floor plan',
            'fp-room' => 'Floor plan',
            'fp-add' => 'Floor plan',
            'fp-grid' => 'Floor plan',
            'fp-3d' => 'Floor plan',
            'nav-datacenters' => 'Inventory',
            'nav-cabinets' => 'Inventory',
            'nav-devices' => 'Inventory',
            'nav-device-templates' => 'Inventory',
            'nav-power' => 'Power',
            'nav-power-zones' => 'Power',
            'nav-power-pdus' => 'Power',
            'nav-power-ups' => 'Power',
            'nav-cooling' => 'Environment',
            'nav-cables' => 'Cabling',
            'nav-snmp' => 'Operations',
            'nav-work-orders' => 'Operations',
            'nav-disposals' => 'Operations',
            'nav-audits' => 'Operations',
            'nav-reports' => 'Operations',
            'nav-users' => 'Admin',
            'nav-settings' => 'Admin',
            'settings-help' => 'Admin',
            'done' => 'Admin',
        ];
        $waitOf = [
            'fp-add' => 'Click + Cabinet (or drag a model from the palette) to continue.',
            'fp-3d' => 'Click 3D View to flip the hall — that counts as trying it. Walk (once 3D is on) puts you in the aisle.',
            'fp-grid' => 'Click Grid to toggle tiles, then continue.',
        ];
        foreach ($steps as &$s) {
            $s['chapter'] = $chapterOf[$s['id']] ?? 'Tour';
            if (isset($waitOf[$s['id']])) {
                $s['wait'] = 'click';
                $s['wait_hint'] = $waitOf[$s['id']];
            }
        }
        unset($s);
        return $steps;
    }

    /** @return list<array<string,mixed>> */
    public static function stepsFor(array $user): array
    {
        $out = [];
        foreach (self::catalog() as $step) {
            $nav = $step['nav'] ?? null;
            if ($nav && class_exists('AuthManager') && !AuthManager::canViewNav($user, (string)$nav)) {
                continue;
            }
            $out[] = $step;
        }
        return array_values($out);
    }

    public static function defaultState(): array
    {
        return [
            'status' => self::STATUS_IDLE,
            'step' => 0,
            'updated_at' => date('c'),
        ];
    }

    public static function loadState(): array
    {
        $raw = class_exists('SettingsService') ? SettingsService::get(self::SETTING_KEY, null) : null;
        if (!is_string($raw) || $raw === '') {
            return self::defaultState();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::defaultState();
        }
        return array_merge(self::defaultState(), $decoded);
    }

    public static function saveState(array $state): void
    {
        $state['updated_at'] = date('c');
        SettingsService::set(self::SETTING_KEY, json_encode($state, JSON_UNESCAPED_SLASHES), 'tour');
    }

    public static function isActive(): bool
    {
        return (self::loadState()['status'] ?? '') === self::STATUS_ACTIVE;
    }

    public static function start(): array
    {
        $state = ['status' => self::STATUS_ACTIVE, 'step' => 0];
        self::saveState($state);
        return $state;
    }

    public static function goto(int $index): array
    {
        $state = self::loadState();
        $state['status'] = self::STATUS_ACTIVE;
        $state['step'] = max(0, $index);
        self::saveState($state);
        return $state;
    }

    public static function exitTour(): array
    {
        $state = self::loadState();
        $state['status'] = self::STATUS_EXITED;
        self::saveState($state);
        return $state;
    }

    public static function complete(): array
    {
        $state = self::loadState();
        $state['status'] = self::STATUS_COMPLETED;
        self::saveState($state);
        return $state;
    }

    /** @return array<string,mixed> */
    public static function payload(array $user): array
    {
        $steps = self::stepsFor($user);
        $state = self::loadState();
        $idx = (int)($state['step'] ?? 0);
        if ($idx >= count($steps)) {
            $idx = max(0, count($steps) - 1);
        }
        $chapters = [];
        foreach ($steps as $i => $s) {
            $ch = (string)($s['chapter'] ?? 'Tour');
            if (!isset($chapters[$ch])) {
                $chapters[$ch] = [
                    'id' => $ch,
                    'label' => $ch,
                    'index' => $i,
                ];
            }
        }

        return [
            'ok' => true,
            'state' => $state,
            'index' => $idx,
            'total' => count($steps),
            'steps' => $steps,
            'chapters' => array_values($chapters),
        ];
    }

    /**
     * Dashboard “do this next” cards. Empty list = site already has the basics.
     *
     * @param array<string,mixed> $user
     * @param array<string,int> $metrics
     * @return list<array{id:string,title:string,detail:string,href:string,cta:string}>
     */
    public static function dashboardQuests(array $user, array $metrics): array
    {
        $canInfra = class_exists('AuthManager') && AuthManager::can($user, 'edit_infrastructure');
        $canDevices = class_exists('AuthManager') && (
            AuthManager::can($user, 'edit_devices_all') || AuthManager::can($user, 'edit_devices_dept')
        );
        $canPower = class_exists('AuthManager') && AuthManager::can($user, 'edit_power');
        $canSettings = class_exists('AuthManager') && AuthManager::can($user, 'manage_settings');
        if (!$canInfra && !$canDevices && !$canPower && !$canSettings) {
            return [];
        }

        $quests = [];
        $cabs = (int)($metrics['cabinets'] ?? 0);
        $devs = (int)($metrics['devices'] ?? 0);
        $pdus = (int)($metrics['pdus'] ?? 0);

        if ($canInfra && $cabs < 1) {
            $quests[] = [
                'id' => 'place-cabinets',
                'title' => 'Place cabinets',
                'detail' => 'The floor plan is empty. Drop racks first — devices need a U to live in.',
                'href' => 'pages/floorplan.php',
                'cta' => 'Open floor plan',
            ];
        }
        if ($canDevices && $devs < 1) {
            $quests[] = [
                'id' => 'add-devices',
                'title' => 'Add a device',
                'detail' => $cabs < 1
                    ? 'After a cabinet exists, add servers and switches from a template.'
                    : 'Racks are ready. Mount the first device so elevations aren’t empty.',
                'href' => 'pages/devices.php?action=new',
                'cta' => 'Add device',
            ];
        }
        if ($canPower && $pdus < 1) {
            $quests[] = [
                'id' => 'add-pdu',
                'title' => 'Hang a PDU',
                'detail' => 'Power charts stay at zero until a PDU exists (and later, SNMP).',
                'href' => 'pages/power_pdus.php',
                'cta' => 'Add PDU',
            ];
        }

        $snmpOn = false;
        if (class_exists('SettingsService')) {
            $snmpOn = SettingsService::get('snmp_scheduler_enabled', '0') === '1'
                || SettingsService::get('snmp_poll_enabled', '0') === '1';
        }
        if ($canSettings && !$snmpOn && ($pdus > 0 || $devs > 0)) {
            $quests[] = [
                'id' => 'enable-snmp',
                'title' => 'Turn on SNMP polling',
                'detail' => 'You have gear with (or ready for) IPs. The scheduler fills history charts.',
                'href' => 'pages/settings.php#snmp-schedule',
                'cta' => 'SNMP schedule',
            ];
        }

        $tour = self::loadState();
        $tourDone = in_array((string)($tour['status'] ?? ''), [self::STATUS_COMPLETED, self::STATUS_ACTIVE], true);
        if ($canSettings && !$tourDone && count($quests) > 0) {
            array_unshift($quests, [
                'id' => 'take-tour',
                'title' => 'Take the site tour',
                'detail' => 'Flags on the live UI — five minutes, exit anytime.',
                'href' => 'index.php?tour=1',
                'cta' => 'Start tour',
            ]);
        }

        return $quests;
    }
}
