<?php
/**
 * In-app documentation — operator how-tos + machine API reference.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/work_order_helpers.php';
App::boot();
$user = App::requireAuth();

$apiUrl = App::url('api/v1.php');
$usersUrl = App::url('pages/users.php');
$version = (string)App::VERSION;
$tokenPrefix = class_exists('ApiTokenService') ? ApiTokenService::PREFIX : 'ca_live_';
$svcPrefix = class_exists('ApiTokenService') ? ApiTokenService::USERNAME_PREFIX : 'api-service-';
$woTypes = work_order_types();
$woStatuses = work_order_statuses();
$woItemStatuses = work_order_item_statuses();

$href = static function (string $navKey, string $path) use ($user): ?string {
    if (!AuthManager::canViewNav($user, $navKey)) {
        return null;
    }
    return App::url($path);
};
$fpUrl = $href('floorplan', 'pages/floorplan.php');
$snmpUrl = $href('snmp', 'pages/snmp.php');
$devUrl = $href('devices', 'pages/devices.php');
$pduUrl = $href('power', 'pages/power_pdus.php');
$woUrl = $href('work_orders', 'pages/work_orders.php');
$cabUrl = $href('cabinets', 'pages/cabinets.php');
$techUrl = App::url('pages/tech.php');
$cablesUrl = $href('cables', 'pages/cables.php');

layout_header('Documentation', $user, 'docs');
?>
<div class="docs-page">
    <div class="flex-between mb-2 docs-intro">
        <div>
            <p class="text-muted mb-0">
                Operator reference for ColdAisle <strong><?= App::e($version) ?></strong>.
                How-tos for the floor planner, SNMP Discover, applying a work order to inventory,
                and Tech / field PWA — plus the external machine API for robots.
            </p>
        </div>
        <nav class="docs-jump" aria-label="Jump to section">
            <a class="settings-jump-chip" href="#floorplan">Floor planner</a>
            <a class="settings-jump-chip" href="#ipam">IPAM</a>
            <a class="settings-jump-chip" href="#snmp-discover">SNMP Discover</a>
            <a class="settings-jump-chip" href="#work-orders">Work-order apply</a>
            <a class="settings-jump-chip" href="#tech-pwa">Tech / PWA</a>
            <a class="settings-jump-chip" href="#api">API</a>
            <a class="settings-jump-chip" href="#auth">Auth</a>
            <a class="settings-jump-chip" href="#endpoints">Calls</a>
            <a class="settings-jump-chip" href="#examples">Examples</a>
        </nav>
    </div>

    <nav class="card docs-toc" aria-label="On this page">
        <div class="card-header"><h2>On this page</h2></div>
        <div class="card-body">
            <p class="docs-toc-label">Using ColdAisle</p>
            <ol class="docs-toc-list">
                <li><a href="#floorplan">Floor planner</a></li>
                <li><a href="#ipam">IPAM</a></li>
                <li><a href="#snmp-discover">SNMP Discover</a></li>
                <li><a href="#work-orders">Work-order apply</a></li>
                <li><a href="#tech-pwa">Tech mode &amp; PWA</a></li>
            </ol>
            <p class="docs-toc-label">Machine API</p>
            <ol class="docs-toc-list">
                <li><a href="#api">External API overview</a></li>
                <li><a href="#security">Security model</a></li>
                <li><a href="#accounts">Service accounts &amp; tokens</a></li>
                <li><a href="#auth">Authentication</a></li>
                <li><a href="#conventions">URL, methods, JSON</a></li>
                <li><a href="#permissions">Permissions</a></li>
                <li><a href="#endpoints">Endpoints</a></li>
                <li><a href="#fields">Response fields</a></li>
                <li><a href="#errors">HTTP status &amp; errors</a></li>
                <li><a href="#examples">Request examples</a></li>
                <li><a href="#notes">Limits &amp; notes</a></li>
            </ol>
        </div>
    </nav>

    <div class="card" id="floorplan">
        <div class="card-header flex-between">
            <h2 style="margin:0">Floor planner</h2>
            <?php if ($fpUrl): ?>
                <a class="btn btn-sm btn-secondary" href="<?= App::e($fpUrl) ?>">Open Floor planner</a>
            <?php endif; ?>
        </div>
        <div class="card-body docs-prose">
            <p>
                Spatial canvas for a hall: cabinets, floor PDUs, UPS, cooling footprints, and cable raceways.
                2D is for drawing; 3D is for checking aisles and troughs. Geometry is stored in
                <strong>meters</strong> on the room (width / depth). Display units (m vs ft) only change labels.
            </p>
            <h3 class="docs-h3">Place a hall</h3>
            <ol>
                <li>Create the room under Data Centers (size in meters). That rectangle is the white floor you see.</li>
                <li>Open Floor planner and pick the room. <strong>Edit Room / North</strong> adjusts size, grid, and which way is north.</li>
                <li><strong>+ Cabinet</strong> or drag a model from the left palette (catalog uses published external W×D). Blue edge is the front of the rack — set <strong>Front faces</strong> after placing.</li>
                <li>Drag to move. <strong>Grid</strong> / <strong>Snap</strong> keep aisles straight. Arrow keys <strong>nudge</strong> a selected unlocked object by the amount in the toolbar.</li>
                <li>Place row PDUs from presets or drag an unplaced PDU onto the plan. Cooling and UPS have the same idea.</li>
            </ol>
            <h3 class="docs-h3">Navigate</h3>
            <ul>
                <li>2D: drag the floor to pan; scroll or +/− to zoom. SHIFT+click multi-selects. Arrow keys nudge a selected unlocked object.</li>
                <li><strong>3D View</strong> is the same geometry, two cameras:
                    <strong>Orbit</strong> is the overview (drag to rotate, scroll to zoom);
                    <strong>Walk</strong> is first-person in an aisle (WASD / arrows, Q/E sidestep, drag to look, Shift faster, Esc back to Orbit).
                </li>
            </ul>
            <h3 class="docs-h3">Raceways</h3>
            <ol>
                <li>Stay on the <strong>2D plan</strong> (drawing from 3D switches you back).</li>
                <li><strong>Draw raceway</strong> — click the floor for vertices (2+). The HUD counts steps.</li>
                <li><strong>Finish</strong> / Enter names the path (RS / ORC / IRC) and saves. Backspace undoes a point; Esc exits without saving. Double-click the floor also finishes when you have 2+ points.</li>
                <li>Optional: drag a round vertex to move that corner. On an L-bend, drag the yellow diamond inward for a 90° curve.</li>
            </ol>
            <ul>
                <li><strong>Clone ladders → U-channel</strong> copies every ladder in the room as yellow fiber U-channel on the same route, typically +10″ elevation. <strong>Raise U-channels +10″</strong> fixes elevation if a clone already exists.</li>
                <li>Filter which networks draw (ladder, fiber U-channel, trough, conduit). From a cable on
                    <?php if ($cablesUrl): ?>
                        <a href="<?= App::e($cablesUrl) ?>">Cabling</a>
                    <?php else: ?>
                        Cabling
                    <?php endif; ?>
                    use <strong>Path</strong> or <strong>Calc path</strong> to overlay the hop sequence here.</li>
            </ul>
            <p class="text-muted" style="margin-bottom:0">
                Planner edits use the logged-in session (CSRF). They are not part of the machine API.
            </p>
        </div>
    </div>

    <div class="card" id="ipam">
        <div class="card-header flex-between">
            <h2 style="margin:0">IPAM</h2>
            <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/ipam.php')) ?>">Open IPAM</a>
        </div>
        <div class="card-body docs-prose">
            <p>
                Two tracking modes. An <strong>address plan</strong> is a prefix plus host records (empty IPs are not stored;
                <strong>Next free</strong> is computed). A <strong>subnet plan</strong> tracks prefixes only — a supernet and
                the smaller blocks carved from it — with no host list. Nested prefixes sit under a parent and stay off the top-level list.
                DHCP on an address plan is a start/end fence, not a DHCP server.
            </p>
            <h3 class="docs-h3">Import from Excel</h3>
            <ul>
                <li>One <strong>.xlsx workbook</strong> is enough. Import → Tracking: Auto, address plan (each row is an IP), or subnet plan (each row is a prefix).</li>
                <li>Subnet-plan sheets typically have Network / CIDR (or mask). An optional parent or supernet column nests rows under a larger prefix.</li>
                <li>Address-plan sheets have host IPs and names. Empty hostname rows are skipped. CIDR can be the tab name or a column.</li>
                <li>CSV is the fallback. Excel import does not require PHP’s zip extension.</li>
            </ul>
            <p class="text-muted" style="margin-bottom:0">
                After import, <strong>Link inventory IPs</strong> matches device / PDU / UPS addresses into the plan.
                Conflicts lists IPs on equipment that are not in any prefix.
            </p>
        </div>
    </div>

    <div class="card" id="snmp-discover">
        <div class="card-header flex-between">
            <h2 style="margin:0">SNMP Discover</h2>
            <?php if ($snmpUrl): ?>
                <a class="btn btn-sm btn-secondary" href="<?= App::e($snmpUrl) ?>">Open SNMP</a>
            <?php endif; ?>
        </div>
        <div class="card-body docs-prose">
            <p>
                Discover lives on the unit — a
                <?php if ($devUrl): ?><a href="<?= App::e($devUrl) ?>">device</a><?php else: ?>device<?php endif; ?>,
                <?php if ($pduUrl): ?><a href="<?= App::e($pduUrl) ?>">PDU</a><?php else: ?>PDU<?php endif; ?>,
                UPS, or cooling unit — not as a separate wizard. It walks the agent, proposes an OID map,
                and saves a <strong>site template</strong> (vendor + model) that later polls reuse.
            </p>
            <h3 class="docs-h3">Before you click Discover</h3>
            <ul>
                <li><strong>PHP SNMP extension</strong> must be loaded on IIS (SNMP page shows a green banner or an Enable SNMP helper).</li>
                <li>The unit needs manufacturer, model, and a reachable address (management / primary IP). Dell prefers the <strong>iDRAC host</strong>.</li>
                <li>Pick an SNMP profile (v2c community or v3). iDRAC often still wants a community even on v3.</li>
            </ul>
            <h3 class="docs-h3">Walk, map, save</h3>
            <ol>
                <li><strong>Discover OIDs</strong> walks common roots (up to about a minute). You get a proposed map plus a candidate table.</li>
                <li>Edit empty OIDs away. Add any candidate as a live field. Optional <strong>Keep history / graph</strong> stores that gauge for 24h charts.</li>
                <li><strong>Create template</strong> (or overwrite if that vendor/model already exists). Scheduled poll unlocks after a site template is assigned.</li>
                <li><strong>Poll now</strong> reads live values. Leave scheduled poll on so the Windows task (<code>poll_snmp.php</code>) keeps history.</li>
            </ol>
            <h3 class="docs-h3">What Discover does not do</h3>
            <ul>
                <li>No SNMP SET and no outlet on/off — ColdAisle only reads.</li>
                <li>The machine API does not run Discover or return live OID walks. Session pages and <code>/api/snmp_*.php</code> stay cookie+CSRF.</li>
                <li>Vendor MIB files on the SNMP page improve <em>names</em> during the walk; they are optional.</li>
            </ul>
        </div>
    </div>

    <div class="card" id="work-orders">
        <div class="card-header flex-between">
            <h2 style="margin:0">Work-order apply</h2>
            <?php if ($woUrl): ?>
                <a class="btn btn-sm btn-secondary" href="<?= App::e($woUrl) ?>">Open work orders</a>
            <?php endif; ?>
        </div>
        <div class="card-body docs-prose">
            <p>
                A work order is the change ticket for rack work: install, move, or other change, with line items
                that name a device and optional from/to cabinet + U. Completing the ticket is not the same as
                moving the devices in inventory — that is <strong>Apply destinations to inventory</strong>.
            </p>
            <h3 class="docs-h3">Statuses</h3>
            <p>
                <?php
                $bits = [];
                foreach ($woStatuses as $k => $label) {
                    $bits[] = '<code>' . App::e((string)$k) . '</code> (' . App::e((string)$label) . ')';
                }
                echo implode(' → ', $bits);
                ?>.
                Typical path: draft → planned → in progress → completed. Cancel never moves devices.
            </p>
            <h3 class="docs-h3">What apply actually does</h3>
            <ol>
                <li>Mark each line item <strong>done</strong> and give it a destination cabinet (and U if you know it).</li>
                <li>While the work order is <strong>in progress</strong>, <strong>Apply inventory now</strong> writes those destinations onto the devices immediately.</li>
                <li>When you <strong>Complete</strong>, leave <strong>Apply destinations to inventory</strong> checked (default) to apply in the same step. Uncheck if you only want to close the ticket.</li>
                <li>On a completed work order, <strong>Re-apply inventory</strong> runs the same write again (useful after you fix a skipped U).</li>
            </ol>
            <ul>
                <li>Only items with status <code>done</code> and a <code>to_cabinet_id</code> are written.</li>
                <li>If the destination U is already occupied by another rack-mounted device, that item is <strong>skipped</strong>; others still apply. Fix the U and re-apply.</li>
                <li>Each successful device write is audited as <code>work_order_apply_device</code>.</li>
                <li>Optional ticketing (Settings → Ticketing) can follow status to ServiceDesk, ServiceNow, Zendesk, Jira, or Freshservice. DCIM stays internal — no inbound webhook required.</li>
            </ul>
            <p class="text-muted" style="margin-bottom:0">
                The machine API can PATCH a work order to <code>completed</code>. That changes status only — it does
                <strong>not</strong> apply destinations. Apply is a session action on this page.
            </p>
        </div>
    </div>

    <div class="card" id="tech-pwa">
        <div class="card-header flex-between">
            <h2 style="margin:0">Tech mode &amp; field PWA</h2>
            <a class="btn btn-sm btn-secondary" href="<?= App::e($techUrl) ?>">Open field hub</a>
        </div>
        <div class="card-body docs-prose">
            <p>
                Desktop is the full planning UI. <strong>Tech</strong> is the same inventory, permissions, and pages
                with field chrome: bigger tap targets, a technician hub, and a bottom bar. Nothing is duplicated —
                audits, power maps, and work orders stay on their real pages.
                The dashboard <strong>Field kit</strong> card is the desktop reminder of how to install the PWA.
            </p>
            <h3 class="docs-h3">Turn it on</h3>
            <ul>
                <li>Header slider: <strong>Desktop</strong> / <strong>Tech</strong>. Tech lands on the field hub.</li>
                <li>URL: <code>?mode=tech</code> (or <code>?field=1</code> from a cabinet QR). Back: <code>?mode=desktop</code> or the slider.</li>
                <li>Hub search: serial, asset tag, label, hostname, IP, or cabinet name. Recent cabinets, overdue audits, and this week’s moves are shortcuts, not a second database.</li>
            </ul>
            <h3 class="docs-h3">QR labels</h3>
            <p>
                Cabinet labels encode a URL that opens that cabinet in field mode
                <?php if ($cabUrl): ?>
                    (print from the cabinet page or bulk sheet on <a href="<?= App::e($cabUrl) ?>">Cabinets</a>).
                <?php else: ?>
                    (print from the cabinet page).
                <?php endif; ?>
                The phone must reach this host (site network or VPN) and the tech must be logged in.
            </p>
            <h3 class="docs-h3">Add to Home Screen</h3>
            <ol>
                <li>Open ColdAisle in the phone/tablet browser (Safari / Edge / Chrome) while logged in.</li>
                <li>Share / menu → <strong>Add to Home Screen</strong>. The icon is <strong>ColdAisle Field</strong>; start URL is the tech hub.</li>
                <li>Launching the icon uses standalone chrome (no browser toolbar).</li>
            </ol>
            <h3 class="docs-h3">Offline</h3>
            <ul>
                <li>A service worker caches the field shell (CSS/JS) and the <strong>last cabinet pages</strong> you actually opened.</li>
                <li>HTML is network-first: if the hall Wi-Fi is up, you get live data. If it drops, you can still <em>read</em> the last elevation you viewed.</li>
                <li>Writes (audit, apply inventory, SNMP) need a live session. Login, settings, and APIs are never served from cache.</li>
            </ul>
        </div>
    </div>

    <div class="card" id="api">
        <div class="card-header"><h2>External API overview</h2></div>
        <div class="card-body docs-prose">
            <p>
                ColdAisle exposes a JSON API at
                <code><?= App::e($apiUrl) ?></code>
                for automation. It is separate from the website:
            </p>
            <ul>
                <li>Browser pages and <code>/api/*.php</code> helpers (floor plan, SNMP, NOC) use a login cookie and CSRF. Those are <strong>not</strong> the integration API.</li>
                <li>Integrations must call <code>/api/v1.php</code> with a Bearer token issued to an <code><?= App::e($svcPrefix) ?>*</code> service account.</li>
                <li><code>GET</code> / <code>HEAD</code> work with a <code>read</code> token. <code>POST</code> / <code>PATCH</code> require a <code>write</code> token <em>and</em> the matching role permission. <code>DELETE</code> is not offered.</li>
            </ul>
            <p>
                Base URL on this host (copy into scripts):
            </p>
            <div class="docs-pre-wrap">
                <pre class="docs-pre" id="docs-base-url"><?= App::e($apiUrl) ?></pre>
                <button type="button" class="btn btn-sm btn-secondary docs-copy" data-copy-target="docs-base-url">Copy</button>
            </div>
        </div>
    </div>

    <div class="card" id="security">
        <div class="card-header"><h2>Security model</h2></div>
        <div class="card-body docs-prose">
            <ul>
                <li><strong>Who mints tokens:</strong> only a Global Admin, from Users → Create API-Service Account (or the token form on an existing service account).</li>
                <li><strong>Who the token is for:</strong> a dedicated robot user, not the Global Admin. The key is tied to that account’s role (Viewer is the usual choice for read).</li>
                <li><strong>No website login:</strong> service accounts have <code>can_login = 0</code>. They cannot use <code>/login.php</code>.</li>
                <li><strong>Role cap:</strong> Global Admin / Administrator cannot be assigned to a service account.</li>
                <li><strong>Storage:</strong> the plaintext token is shown <em>once</em>. ColdAisle stores HMAC-SHA256 of the secret (peppered with the site <code>app_key</code>), plus a short prefix for lookup. Lost tokens must be revoked and replaced.</li>
                <li><strong>Prefix:</strong> live tokens start with <code><?= App::e($tokenPrefix) ?></code>. Treat the whole string as a password.</li>
                <li><strong>Secrets stripped:</strong> SNMP communities, SNMPv3 passphrases, password hashes, LDAP bind passwords, and OAuth client secrets are never returned.</li>
                <li><strong>Write scope:</strong> mint <code>write</code> only for robots that must change inventory. Writes still need the service account’s role (e.g. <code>edit_work_orders</code>). Prefer <code>read</code> when a CMDB only pulls.</li>
            </ul>
        </div>
    </div>

    <div class="card" id="accounts">
        <div class="card-header"><h2>Service accounts &amp; tokens</h2></div>
        <div class="card-body docs-prose">
            <ol>
                <li>Open <a href="<?= App::e($usersUrl) ?>">Users &amp; Depts</a> as Global Admin.</li>
                <li>Click <strong>Create API-Service Account</strong>.</li>
                <li>Enter a short name (letters, numbers, hyphens). The username becomes <code><?= App::e($svcPrefix) ?>{name}</code> — e.g. <code><?= App::e($svcPrefix) ?>servicenow</code>.</li>
                <li>Pick a least-privilege role (Viewer for inventory reads). Optional department.</li>
                <li>Leave <strong>Create an API token now</strong> checked. Copy the token from the yellow banner — it will not be shown again.</li>
            </ol>
            <p>
                Later: open the service account → <strong>API tokens</strong> to mint another token or revoke one. Revoking stops that secret immediately; the account can still hold other tokens.
            </p>
            <table class="data">
                <thead>
                <tr><th>Property</th><th>Value</th></tr>
                </thead>
                <tbody>
                <tr><td>Username prefix</td><td><code><?= App::e($svcPrefix) ?></code></td></tr>
                <tr><td>Token prefix</td><td><code><?= App::e($tokenPrefix) ?></code></td></tr>
                <tr><td>Token scopes</td><td><code>read</code> (GET) or <code>write</code> (GET + PATCH/POST allowlist)</td></tr>
                <tr><td>Expiry options</td><td>Never, 90 days, or 1 year (set at mint time)</td></tr>
                <tr><td>Last used</td><td>Updated at most once per minute on a successful call</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" id="auth">
        <div class="card-header"><h2>Authentication</h2></div>
        <div class="card-body docs-prose">
            <p>Every call (except <code>OPTIONS</code>) must send:</p>
            <div class="docs-pre-wrap">
                <pre class="docs-pre" id="docs-auth-header">Authorization: Bearer <?= App::e($tokenPrefix) ?>…</pre>
                <button type="button" class="btn btn-sm btn-secondary docs-copy" data-copy-target="docs-auth-header">Copy</button>
            </div>
            <ul>
                <li>Do <strong>not</strong> put the token in the query string, cookies, or a JSON body.</li>
                <li>Do <strong>not</strong> send a CSRF token or session cookie. This endpoint does not use them.</li>
                <li>Scheme is case-insensitive <code>Bearer</code>; the token itself is case-sensitive.</li>
                <li>IIS FastCGI should forward the Authorization header. PHP looks at <code>HTTP_AUTHORIZATION</code> and <code>REDIRECT_HTTP_AUTHORIZATION</code>. A valid token that still returns 401 often means IIS stripped the header.</li>
            </ul>
        </div>
    </div>

    <div class="card" id="conventions">
        <div class="card-header"><h2>URL, methods, JSON</h2></div>
        <div class="card-body docs-prose">
            <p>
                Resources hang off <code>v1.php</code> as PATH_INFO. Both of these are valid on IIS:
            </p>
            <ul>
                <li><code><?= App::e($apiUrl) ?>/cabinets</code></li>
                <li><code><?= App::e($apiUrl) ?>/cabinets/12</code></li>
                <li>Work orders also accept <code>/work-orders</code> (hyphen) as an alias of <code>/work_orders</code>.</li>
            </ul>
            <table class="data">
                <thead>
                <tr><th>Rule</th><th>Detail</th></tr>
                </thead>
                <tbody>
                <tr><td>Content-Type</td><td><code>application/json; charset=utf-8</code></td></tr>
                <tr><td>JSON encoding</td><td>Unescaped Unicode and slashes; invalid UTF-8 is substituted</td></tr>
                <tr><td>Booleans</td><td>JSON <code>true</code> / <code>false</code> (SQL <code>BIT</code> is converted; not <code>0</code>/<code>1</code>)</td></tr>
                <tr><td>Dates</td><td>ISO-like strings from SQL Server (<code>YYYY-MM-DD</code> or <code>YYYY-MM-DD HH:MM:SS</code>)</td></tr>
                <tr><td>Pagination</td><td>Lists: <code>page</code> (1-based, default 1) and <code>per_page</code> or <code>per</code> (default 50, max 200). Response includes <code>total</code> and <code>pages</code>.</td></tr>
                <tr><td>HEAD</td><td>Same as GET without a body</td></tr>
                <tr><td>OPTIONS</td><td>Returns <code>Allow: GET, HEAD, POST, PATCH, OPTIONS</code></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" id="permissions">
        <div class="card-header"><h2>Permissions</h2></div>
        <div class="card-body docs-prose">
            <p>
                After the token is accepted, each resource still checks the service account’s <strong>role</strong>.
                Missing permission returns HTTP 403 with the permission key.
            </p>
            <table class="data">
                <thead>
                <tr><th>Resource</th><th>Permission</th><th>Typical role</th></tr>
                </thead>
                <tbody>
                <tr><td><code>GET /api/v1.php</code> (status)</td><td>None beyond a valid token</td><td>Any service-account role</td></tr>
                <tr><td>Cabinets</td><td><code>view_cabinets</code></td><td>Viewer</td></tr>
                <tr><td>Devices GET</td><td><code>view_devices</code></td><td>Viewer</td></tr>
                <tr><td>Devices PATCH / notes</td><td>Token <code>write</code> + <code>edit_devices_*</code></td><td>Department or DC Admin</td></tr>
                <tr><td>PDUs / UPS</td><td><code>view_power</code></td><td>Viewer</td></tr>
                <tr><td>Work orders GET</td><td><code>view_work_orders</code></td><td>Viewer</td></tr>
                <tr><td>Work orders POST/PATCH</td><td>Token <code>write</code> + <code>edit_work_orders</code></td><td>DC Admin</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" id="endpoints">
        <div class="card-header"><h2>Endpoints</h2></div>
        <div class="card-body docs-prose">
            <p>Replace <code>{id}</code> with a positive integer. Query parameters are optional.</p>

            <h3 class="docs-h3"><span class="docs-method">GET</span> Status</h3>
            <p class="docs-path"><code><?= App::e($apiUrl) ?></code></p>
            <p>Health / identity probe. Confirms the token works and reports the bound account.</p>
            <table class="data">
                <thead><tr><th>Field</th><th>Type</th><th>Notes</th></tr></thead>
                <tbody>
                <tr><td><code>ok</code></td><td>boolean</td><td>Always <code>true</code> on success</td></tr>
                <tr><td><code>api</code></td><td>string</td><td><code>coldaisle</code></td></tr>
                <tr><td><code>version</code></td><td>string</td><td>App version, currently <code><?= App::e($version) ?></code></td></tr>
                <tr><td><code>account</code></td><td>string</td><td>Service-account username</td></tr>
                <tr><td><code>role</code></td><td>string</td><td>Role name (e.g. Viewer)</td></tr>
                <tr><td><code>scopes</code></td><td>string</td><td>Token scope, usually <code>read</code></td></tr>
                <tr><td><code>resources</code></td><td>string[]</td><td><code>cabinets</code>, <code>devices</code>, <code>pdus</code>, <code>ups</code>, <code>work_orders</code></td></tr>
                <tr><td><code>writes</code></td><td>boolean</td><td>Whether this token’s scope includes write</td></tr>
                </tbody>
            </table>

            <h3 class="docs-h3"><span class="docs-method">GET</span> List cabinets</h3>
            <p class="docs-path"><code><?= App::e($apiUrl) ?>/cabinets</code></p>
            <p>Query: <code>q</code>, <code>page</code>, <code>per_page</code>. Wrapper: <code>cabinets</code> plus pagination fields.</p>

            <h3 class="docs-h3"><span class="docs-method">GET</span> One cabinet</h3>
            <p class="docs-path"><code><?= App::e($apiUrl) ?>/cabinets/{id}</code></p>
            <p>Full cabinet row plus <code>row_name</code> and <code>room_name</code>. Wrapper: <code>cabinet</code>.</p>

            <h3 class="docs-h3"><span class="docs-method">GET</span> List devices</h3>
            <p class="docs-path"><code><?= App::e($apiUrl) ?>/devices</code></p>
            <table class="data">
                <thead><tr><th>Query</th><th>Type</th><th>Notes</th></tr></thead>
                <tbody>
                <tr><td><code>cabinet_id</code></td><td>integer</td><td>If &gt; 0, only devices in that cabinet.</td></tr>
                <tr><td><code>q</code></td><td>string</td><td>Label, hostname, serial, asset tag, IP, or id.</td></tr>
                <tr><td><code>page</code> / <code>per_page</code></td><td>integer</td><td>Pagination (default 50, max 200).</td></tr>
                </tbody>
            </table>
            <p>Active devices only. Wrapper: <code>devices</code>.</p>

            <h3 class="docs-h3"><span class="docs-method">GET</span> One device</h3>
            <p class="docs-path"><code><?= App::e($apiUrl) ?>/devices/{id}</code></p>
            <p>Includes <code>primary_ip</code>, <code>mgmt_ip</code>, <code>hostname</code>, <code>status</code>. Wrapper: <code>device</code>.</p>

            <h3 class="docs-h3"><span class="docs-method docs-method-write">PATCH</span> Update device</h3>
            <p class="docs-path"><code><?= App::e($apiUrl) ?>/devices/{id}</code></p>
            <p>Write scope. Body JSON, only listed keys are applied:</p>
            <p><code>label</code>, <code>serial_no</code>, <code>asset_tag</code>, <code>hostname</code>, <code>primary_ip</code>, <code>mgmt_ip</code>, <code>notes</code>, <code>status</code>, <code>cabinet_id</code> (null un-racks), <code>position_u</code>, <code>u_height</code>.</p>
            <p>U-space conflicts return 400. SNMP secrets cannot be written.</p>

            <h3 class="docs-h3"><span class="docs-method docs-method-write">POST</span> Add device note</h3>
            <p class="docs-path"><code><?= App::e($apiUrl) ?>/devices/{id}/notes</code></p>
            <p>Body: <code>{"note_text": "…"}</code>. HTTP 201. Write scope + device edit permission.</p>

            <h3 class="docs-h3"><span class="docs-method">GET</span> List PDUs</h3>
            <p class="docs-path"><code><?= App::e($apiUrl) ?>/pdus</code></p>
            <p>Query: <code>q</code>, <code>zone_id</code>, <code>page</code>, <code>per_page</code>. Active units. Wrapper: <code>pdus</code>.</p>

            <h3 class="docs-h3"><span class="docs-method">GET</span> One PDU</h3>
            <p class="docs-path"><code><?= App::e($apiUrl) ?>/pdus/{id}</code></p>
            <p>Includes load, SNMP flags (not communities/passphrases), cabinet/row/zone names. Wrapper: <code>pdu</code>.</p>

            <h3 class="docs-h3"><span class="docs-method">GET</span> List UPS</h3>
            <p class="docs-path"><code><?= App::e($apiUrl) ?>/ups</code></p>
            <p>Query: <code>q</code>, <code>page</code>, <code>per_page</code>. Wrapper: <code>ups</code>.</p>

            <h3 class="docs-h3"><span class="docs-method">GET</span> One UPS</h3>
            <p class="docs-path"><code><?= App::e($apiUrl) ?>/ups/{id}</code></p>
            <p>Load %, battery %, identity. Wrapper: <code>ups</code>.</p>

            <h3 class="docs-h3"><span class="docs-method">GET</span> List work orders</h3>
            <p class="docs-path"><code><?= App::e($apiUrl) ?>/work_orders</code></p>
            <table class="data">
                <thead><tr><th>Query</th><th>Type</th><th>Notes</th></tr></thead>
                <tbody>
                <tr>
                    <td><code>status</code></td>
                    <td>string</td>
                    <td>Exact match, lowercased. Values: <?php
                        $bits = [];
                        foreach ($woStatuses as $k => $label) {
                            $bits[] = '<code>' . App::e($k) . '</code> (' . App::e($label) . ')';
                        }
                        echo implode(', ', $bits);
                        ?>.</td>
                </tr>
                <tr><td><code>q</code></td><td>string</td><td>Title, ticket, ITSM id, or numeric id.</td></tr>
                </tbody>
            </table>
            <p>Newest <code>updated_at</code> first. Paginated. Wrapper: <code>work_orders</code>.</p>

            <h3 class="docs-h3"><span class="docs-method">GET</span> One work order</h3>
            <p class="docs-path"><code><?= App::e($apiUrl) ?>/work_orders/{id}</code></p>
            <p>Full row plus <code>items</code>.</p>

            <h3 class="docs-h3"><span class="docs-method docs-method-write">POST</span> Create work order</h3>
            <p class="docs-path"><code><?= App::e($apiUrl) ?>/work_orders</code></p>
            <p>Write scope. HTTP 201. Body: <code>title</code> (required), <code>work_type</code>, <code>change_ticket</code>, <code>scheduled_date</code>, <code>notes</code>, <code>assigned_to</code>, optional seed <code>device_id</code> + <code>to_cabinet_id</code> / <code>to_position_u</code>. Created as <code>draft</code>. Completing a WO via PATCH does <strong>not</strong> apply inventory — that stays in the UI.</p>

            <h3 class="docs-h3"><span class="docs-method docs-method-write">PATCH</span> Update work order</h3>
            <p class="docs-path"><code><?= App::e($apiUrl) ?>/work_orders/{id}</code></p>
            <p>Allowlist: <code>title</code>, <code>work_type</code>, <code>status</code>, <code>change_ticket</code>, <code>scheduled_date</code>, <code>notes</code>, <code>assigned_to</code>.</p>

            <h3 class="docs-h3"><span class="docs-method docs-method-write">POST</span> Add work-order item</h3>
            <p class="docs-path"><code><?= App::e($apiUrl) ?>/work_orders/{id}/items</code></p>
            <p>Body: <code>device_id</code> (required), <code>to_cabinet_id</code>, <code>to_position_u</code>. HTTP 201. Returns the work order + items.</p>
        </div>
    </div>

    <div class="card" id="fields">
        <div class="card-header"><h2>Response fields</h2></div>
        <div class="card-body docs-prose">
            <h3 class="docs-h3">Cabinets — list</h3>
            <table class="data">
                <thead><tr><th>Field</th><th>Type</th><th>Notes</th></tr></thead>
                <tbody>
                <tr><td><code>cabinet_id</code></td><td>int</td><td>Primary key</td></tr>
                <tr><td><code>name</code></td><td>string</td><td>Rack name</td></tr>
                <tr><td><code>u_height</code></td><td>int</td><td>Rack units (typically 42)</td></tr>
                <tr><td><code>is_active</code></td><td>boolean</td><td>Soft-delete flag</td></tr>
                <tr><td><code>row_name</code></td><td>string|null</td><td>Joined from the row</td></tr>
                <tr><td><code>room_name</code></td><td>string|null</td><td>Joined from the room</td></tr>
                </tbody>
            </table>

            <h3 class="docs-h3">Cabinets — detail</h3>
            <p>List fields plus the full cabinet record (after secret stripping):</p>
            <table class="data">
                <thead><tr><th>Field</th><th>Type</th><th>Notes</th></tr></thead>
                <tbody>
                <tr><td><code>room_id</code></td><td>int</td><td>Parent room</td></tr>
                <tr><td><code>row_id</code></td><td>int|null</td><td>Parent row</td></tr>
                <tr><td><code>location_tag</code></td><td>string|null</td><td>Optional site tag</td></tr>
                <tr><td><code>width_mm</code> / <code>depth_mm</code></td><td>int</td><td>Footprint in millimetres</td></tr>
                <tr><td><code>max_weight_kg</code> / <code>max_kw</code></td><td>number|null</td><td>Rated limits</td></tr>
                <tr><td><code>pos_x</code>, <code>pos_y</code>, <code>pos_z</code></td><td>number</td><td>Floor-plan position in metres</td></tr>
                <tr><td><code>rotation_deg</code></td><td>number</td><td>Yaw on the floor plan</td></tr>
                <tr><td><code>color_hex</code></td><td>string</td><td>UI colour, e.g. <code>#2d3748</code></td></tr>
                <tr><td><code>front_facing</code></td><td>string</td><td><code>north</code>, <code>south</code>, <code>east</code>, <code>west</code></td></tr>
                <tr><td><code>model_key</code></td><td>string|null</td><td>3D model variant</td></tr>
                <tr><td><code>notes</code></td><td>string|null</td><td></td></tr>
                <tr><td><code>installation_date</code></td><td>date|null</td><td></td></tr>
                <tr><td><code>audit_interval_days</code></td><td>int|null</td><td>Override; null = site default</td></tr>
                <tr><td><code>created_at</code> / <code>updated_at</code></td><td>datetime</td><td>UTC-style SQL timestamps</td></tr>
                </tbody>
            </table>

            <h3 class="docs-h3">Devices</h3>
            <table class="data">
                <thead><tr><th>Field</th><th>List</th><th>Detail</th><th>Notes</th></tr></thead>
                <tbody>
                <tr><td><code>device_id</code></td><td>✓</td><td>✓</td><td>Primary key</td></tr>
                <tr><td><code>label</code></td><td>✓</td><td>✓</td><td>Device name</td></tr>
                <tr><td><code>serial_no</code></td><td>✓</td><td>✓</td><td></td></tr>
                <tr><td><code>asset_tag</code></td><td>✓</td><td>✓</td><td></td></tr>
                <tr><td><code>cabinet_id</code></td><td>✓</td><td>✓</td><td>Null if unracked</td></tr>
                <tr><td><code>cabinet_name</code></td><td>✓</td><td>✓</td><td>Joined name</td></tr>
                <tr><td><code>position_u</code></td><td>✓</td><td>✓</td><td>Bottom U (1 = lowest)</td></tr>
                <tr><td><code>u_height</code></td><td>✓</td><td>✓</td><td>Occupied U</td></tr>
                <tr><td><code>device_type</code></td><td>✓</td><td>✓</td><td>e.g. server, pdu, network_switch, patch_panel</td></tr>
                <tr><td><code>manufacturer</code> / <code>model</code></td><td>✓</td><td>✓</td><td></td></tr>
                <tr><td><code>is_active</code></td><td>✓</td><td>✓</td><td>JSON boolean; list is active-only</td></tr>
                <tr><td><code>status</code></td><td>✓</td><td>✓</td><td>production, testing, …</td></tr>
                <tr><td><code>primary_ip</code></td><td>✓</td><td>✓</td><td></td></tr>
                <tr><td><code>mgmt_ip</code> / <code>hostname</code></td><td></td><td>✓</td><td></td></tr>
                </tbody>
            </table>
            <p class="text-muted">
                Detail does not currently return SNMP credentials, iDRAC passwords, or other secret columns.
            </p>

            <h3 class="docs-h3">PDUs</h3>
            <p>List/detail: <code>pdu_id</code>, <code>name</code>, <code>ip_address</code>, <code>pdu_scope</code>, <code>is_active</code>, <code>rated_amps</code>, <code>last_poll_watts</code>, <code>snmp_enabled</code> (boolean, no secrets), <code>cabinet_name</code>, <code>row_name</code>, <code>zone_name</code>. Detail also has volts, amps, poll time, serial, manufacturer, model.</p>

            <h3 class="docs-h3">UPS</h3>
            <p>List/detail: <code>ups_id</code>, <code>name</code>, <code>primary_ip</code>, <code>ups_scope</code>, <code>manufacturer</code>, <code>model</code>, <code>is_active</code>, <code>last_load_pct</code>, <code>last_battery_pct</code>, <code>room_name</code>, <code>zone_name</code>. Detail also has serial, asset tag, rated kVA/kW, output status.</p>

            <h3 class="docs-h3">Work orders — list</h3>
            <table class="data">
                <thead><tr><th>Field</th><th>Type</th><th>Notes</th></tr></thead>
                <tbody>
                <tr><td><code>work_order_id</code></td><td>int</td><td>Primary key</td></tr>
                <tr><td><code>title</code></td><td>string</td><td></td></tr>
                <tr><td><code>work_type</code></td><td>string</td><td><?php
                    $bits = [];
                    foreach ($woTypes as $k => $label) {
                        $bits[] = '<code>' . App::e($k) . '</code> (' . App::e($label) . ')';
                    }
                    echo implode(', ', $bits);
                    ?></td></tr>
                <tr><td><code>status</code></td><td>string</td><td><?php
                    $bits = [];
                    foreach ($woStatuses as $k => $label) {
                        $bits[] = '<code>' . App::e($k) . '</code>';
                    }
                    echo implode(', ', $bits);
                    ?></td></tr>
                <tr><td><code>change_ticket</code></td><td>string|null</td><td>Manual ticket number</td></tr>
                <tr><td><code>scheduled_date</code></td><td>date|null</td><td></td></tr>
                <tr><td><code>itsm_provider</code></td><td>string|null</td><td>Active ticketing system key when linked</td></tr>
                <tr><td><code>itsm_display_id</code></td><td>string|null</td><td>Human ticket id from the provider</td></tr>
                <tr><td><code>updated_at</code></td><td>datetime</td><td></td></tr>
                </tbody>
            </table>

            <h3 class="docs-h3">Work orders — detail</h3>
            <p>List fields plus the rest of the row:</p>
            <table class="data">
                <thead><tr><th>Field</th><th>Type</th><th>Notes</th></tr></thead>
                <tbody>
                <tr><td><code>requested_by</code> / <code>assigned_to</code></td><td>int|null</td><td>User ids</td></tr>
                <tr><td><code>completed_at</code></td><td>datetime|null</td><td></td></tr>
                <tr><td><code>notes</code></td><td>string|null</td><td></td></tr>
                <tr><td><code>checklist_json</code></td><td>string|null</td><td>JSON array of checklist items</td></tr>
                <tr><td><code>itsm_request_id</code></td><td>string|null</td><td>Provider’s internal id</td></tr>
                <tr><td><code>itsm_url</code></td><td>string|null</td><td>Deep link in the ticketing UI</td></tr>
                <tr><td><code>itsm_last_sync_at</code></td><td>datetime|null</td><td></td></tr>
                <tr><td><code>itsm_last_error</code></td><td>string|null</td><td>Last outbound/pull error</td></tr>
                <tr><td><code>created_at</code></td><td>datetime</td><td></td></tr>
                </tbody>
            </table>

            <h3 class="docs-h3">Work-order items</h3>
            <table class="data">
                <thead><tr><th>Field</th><th>Type</th><th>Notes</th></tr></thead>
                <tbody>
                <tr><td><code>item_id</code></td><td>int</td><td></td></tr>
                <tr><td><code>work_order_id</code></td><td>int</td><td></td></tr>
                <tr><td><code>device_id</code></td><td>int</td><td>Device being moved/installed</td></tr>
                <tr><td><code>from_cabinet_id</code> / <code>from_position_u</code></td><td>int|null</td><td>Source rack / U</td></tr>
                <tr><td><code>to_cabinet_id</code> / <code>to_position_u</code></td><td>int|null</td><td>Destination rack / U</td></tr>
                <tr><td><code>item_status</code></td><td>string</td><td><?php
                    $bits = [];
                    foreach ($woItemStatuses as $k => $label) {
                        $bits[] = '<code>' . App::e($k) . '</code> (' . App::e($label) . ')';
                    }
                    echo implode(', ', $bits);
                    ?></td></tr>
                <tr><td><code>sort_order</code></td><td>int</td><td>Display order</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" id="errors">
        <div class="card-header"><h2>HTTP status &amp; errors</h2></div>
        <div class="card-body docs-prose">
            <p>Error bodies are JSON objects with at least <code>error</code> (string).</p>
            <table class="data">
                <thead><tr><th>HTTP</th><th>When</th><th>Body extras</th></tr></thead>
                <tbody>
                <tr><td><code>200</code></td><td>Success</td><td>Resource wrapper as documented</td></tr>
                <tr><td><code>201</code></td><td>Created (work order, item, note)</td><td></td></tr>
                <tr><td><code>400</code></td><td>Validation (empty title, U conflict, unknown status)</td><td><code>error</code></td></tr>
                <tr><td><code>401</code></td><td>Missing, invalid, or revoked token</td><td><code>error</code> message</td></tr>
                <tr><td><code>403</code></td><td>Disabled account, missing role permission, or read token on a write call</td><td><code>permission</code> or <code>scopes</code></td></tr>
                <tr><td><code>404</code></td><td>Unknown id, or unknown resource name</td><td><code>resource</code> on unknown path</td></tr>
                <tr><td><code>405</code></td><td>Unsupported method on that path</td><td></td></tr>
                <tr><td><code>500</code></td><td>Unexpected server error</td><td>Logged server-side</td></tr>
                <tr><td><code>503</code></td><td>Not installed, or API token code not deployed</td><td></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" id="examples">
        <div class="card-header"><h2>Request examples</h2></div>
        <div class="card-body docs-prose">
            <p>Replace the token with the value copied at mint time. Do not commit tokens to source control.</p>

            <h3 class="docs-h3">curl</h3>
            <div class="docs-pre-wrap">
<pre class="docs-pre" id="docs-ex-curl">curl -sS -H "Authorization: Bearer <?= App::e($tokenPrefix) ?>YOUR_TOKEN" \
  "<?= App::e($apiUrl) ?>"

curl -sS -H "Authorization: Bearer <?= App::e($tokenPrefix) ?>YOUR_TOKEN" \
  "<?= App::e($apiUrl) ?>/cabinets"

curl -sS -H "Authorization: Bearer <?= App::e($tokenPrefix) ?>YOUR_TOKEN" \
  "<?= App::e($apiUrl) ?>/devices?cabinet_id=12&page=1&per_page=50"

curl -sS -H "Authorization: Bearer <?= App::e($tokenPrefix) ?>YOUR_TOKEN" \
  "<?= App::e($apiUrl) ?>/pdus?zone_id=1"

curl -sS -H "Authorization: Bearer <?= App::e($tokenPrefix) ?>YOUR_TOKEN" \
  "<?= App::e($apiUrl) ?>/work_orders?status=in_progress"

curl -sS -X PATCH -H "Authorization: Bearer <?= App::e($tokenPrefix) ?>YOUR_WRITE_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"primary_ip\":\"10.0.0.12\"}" \
  "<?= App::e($apiUrl) ?>/devices/12"</pre>
                <button type="button" class="btn btn-sm btn-secondary docs-copy" data-copy-target="docs-ex-curl">Copy</button>
            </div>

            <h3 class="docs-h3">PowerShell</h3>
            <div class="docs-pre-wrap">
<pre class="docs-pre" id="docs-ex-ps">$token = '<?= App::e($tokenPrefix) ?>YOUR_TOKEN'
$h = @{ Authorization = "Bearer $token" }
Invoke-RestMethod -Headers $h -Uri '<?= App::e($apiUrl) ?>'
Invoke-RestMethod -Headers $h -Uri '<?= App::e($apiUrl) ?>/cabinets'
Invoke-RestMethod -Headers $h -Uri '<?= App::e($apiUrl) ?>/devices?cabinet_id=12'
Invoke-RestMethod -Headers $h -Uri '<?= App::e($apiUrl) ?>/work_orders?status=planned'</pre>
                <button type="button" class="btn btn-sm btn-secondary docs-copy" data-copy-target="docs-ex-ps">Copy</button>
            </div>

            <h3 class="docs-h3">Python</h3>
            <div class="docs-pre-wrap">
<pre class="docs-pre" id="docs-ex-py">import requests

base = "<?= App::e($apiUrl) ?>"
headers = {"Authorization": "Bearer <?= App::e($tokenPrefix) ?>YOUR_TOKEN"}

status = requests.get(base, headers=headers, timeout=30).json()
cabinets = requests.get(base + "/cabinets", headers=headers, params={"page": 1, "per_page": 50}, timeout=30).json()
devices = requests.get(base + "/devices", headers=headers, params={"cabinet_id": 12}, timeout=30).json()["devices"]
pdus = requests.get(base + "/pdus", headers=headers, timeout=30).json()["pdus"]</pre>
                <button type="button" class="btn btn-sm btn-secondary docs-copy" data-copy-target="docs-ex-py">Copy</button>
            </div>
        </div>
    </div>

    <div class="card" id="notes">
        <div class="card-header"><h2>Limits &amp; notes</h2></div>
        <div class="card-body docs-prose">
            <ul>
                <li><strong>Not in v1:</strong> cooling, cabling, floor-plan geometry, SNMP live OIDs, users, PDU outlet maps, or applying a completed work order to inventory. Completing a WO via PATCH only changes status.</li>
                <li><strong>No power control.</strong> The API never issues SNMP SET / outlet on-off.</li>
                <li><strong>Internal APIs</strong> under <code>/api/</code> (except <code>v1.php</code>) stay session-authenticated. Do not point an ITSM robot at them.</li>
                <li><strong>Keep DCIM internal.</strong> Tokens are long-lived secrets. Prefer a trusted network or jump host.</li>
                <li>If you rotate <code>app_key</code>, existing token hashes no longer verify — mint new tokens.</li>
            </ul>
        </div>
    </div>
</div>
<script>
(function () {
  document.querySelectorAll('.docs-copy').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-copy-target');
      var el = id ? document.getElementById(id) : null;
      var text = el ? el.textContent : '';
      if (!text) return;
      var done = function () {
        var prev = btn.textContent;
        btn.textContent = 'Copied';
        setTimeout(function () { btn.textContent = prev; }, 1400);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(function () {
          window.prompt('Copy', text);
        });
      } else {
        window.prompt('Copy', text);
      }
    });
  });
})();
</script>
<?php
layout_footer();
