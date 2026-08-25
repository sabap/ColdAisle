<?php
/**
 * In-app documentation — machine API reference for operators.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/work_order_helpers.php';
App::boot();
$user = App::requirePermission('manage_users');

$apiUrl = App::url('api/v1.php');
$usersUrl = App::url('pages/users.php');
$version = (string)App::VERSION;
$tokenPrefix = class_exists('ApiTokenService') ? ApiTokenService::PREFIX : 'ca_live_';
$svcPrefix = class_exists('ApiTokenService') ? ApiTokenService::USERNAME_PREFIX : 'api-service-';
$woTypes = work_order_types();
$woStatuses = work_order_statuses();
$woItemStatuses = work_order_item_statuses();

layout_header('Documentation', $user, 'docs');
?>
<div class="docs-page">
    <div class="flex-between mb-2 docs-intro">
        <div>
            <p class="text-muted mb-0">
                Operator reference for ColdAisle <strong><?= App::e($version) ?></strong>.
                This page describes the external machine API — the same surface a ServiceNow,
                script, or other system uses with a service-account token.
            </p>
        </div>
        <div class="flex gap-1">
            <a class="btn btn-secondary btn-sm" href="#api">API</a>
            <a class="btn btn-secondary btn-sm" href="#auth">Auth</a>
            <a class="btn btn-secondary btn-sm" href="#endpoints">Calls</a>
            <a class="btn btn-secondary btn-sm" href="#fields">Fields</a>
            <a class="btn btn-secondary btn-sm" href="#examples">Examples</a>
        </div>
    </div>

    <nav class="card docs-toc" aria-label="On this page">
        <div class="card-header"><h2>On this page</h2></div>
        <div class="card-body">
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

    <div class="card" id="api">
        <div class="card-header"><h2>External API overview</h2></div>
        <div class="card-body docs-prose">
            <p>
                ColdAisle exposes a <strong>read-only JSON API</strong> at
                <code><?= App::e($apiUrl) ?></code>
                for automation. It is separate from the website:
            </p>
            <ul>
                <li>Browser pages and <code>/api/*.php</code> helpers (floor plan, SNMP, NOC) use a login cookie and CSRF. Those are <strong>not</strong> the integration API.</li>
                <li>Integrations must call <code>/api/v1.php</code> with a Bearer token issued to an <code><?= App::e($svcPrefix) ?>*</code> service account.</li>
                <li>This version accepts <code>GET</code>, <code>HEAD</code>, and <code>OPTIONS</code> only. <code>POST</code> / <code>PUT</code> / <code>PATCH</code> / <code>DELETE</code> return <code>405</code>.</li>
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
                <li><strong>Write scope:</strong> a token may be minted with scope <code>write</code>, but <strong>v1 ignores it</strong> — the API is still read-only. Prefer <code>read</code>.</li>
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
                <tr><td>Token scopes</td><td><code>read</code> (recommended) or <code>write</code> (reserved; v1 is GET-only)</td></tr>
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
                <tr><td>Booleans</td><td>SQL Server <code>BIT</code> fields usually arrive as <code>0</code> / <code>1</code>, not JSON true/false</td></tr>
                <tr><td>Dates</td><td>ISO-like strings from SQL Server (<code>YYYY-MM-DD</code> or <code>YYYY-MM-DD HH:MM:SS</code>)</td></tr>
                <tr><td>Pagination</td><td>None in v1 — list endpoints return the full result set</td></tr>
                <tr><td>HEAD</td><td>Same as GET without a body</td></tr>
                <tr><td>OPTIONS</td><td>Returns <code>Allow: GET, HEAD, OPTIONS</code> (no auth required for the preflight itself)</td></tr>
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
                <tr><td>Devices</td><td><code>view_devices</code></td><td>Viewer</td></tr>
                <tr><td>Work orders</td><td><code>view_work_orders</code></td><td>Viewer</td></tr>
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
                <tr><td><code>resources</code></td><td>string[]</td><td><code>cabinets</code>, <code>devices</code>, <code>work_orders</code></td></tr>
                </tbody>
            </table>

            <h3 class="docs-h3"><span class="docs-method">GET</span> List cabinets</h3>
            <p class="docs-path"><code><?= App::e($apiUrl) ?>/cabinets</code></p>
            <p>All cabinets (active and inactive), ordered by name. Wrapper key: <code>cabinets</code>.</p>

            <h3 class="docs-h3"><span class="docs-method">GET</span> One cabinet</h3>
            <p class="docs-path"><code><?= App::e($apiUrl) ?>/cabinets/{id}</code></p>
            <p>Full cabinet row plus <code>row_name</code> and <code>room_name</code>. Wrapper key: <code>cabinet</code>. Unknown id → 404.</p>

            <h3 class="docs-h3"><span class="docs-method">GET</span> List devices</h3>
            <p class="docs-path"><code><?= App::e($apiUrl) ?>/devices</code></p>
            <table class="data">
                <thead><tr><th>Query</th><th>Type</th><th>Notes</th></tr></thead>
                <tbody>
                <tr>
                    <td><code>cabinet_id</code></td>
                    <td>integer</td>
                    <td>If &gt; 0, only devices in that cabinet. Omit for the whole site.</td>
                </tr>
                </tbody>
            </table>
            <p>Active devices only (<code>is_active = 1</code>), ordered by label. Wrapper key: <code>devices</code>.</p>

            <h3 class="docs-h3"><span class="docs-method">GET</span> One device</h3>
            <p class="docs-path"><code><?= App::e($apiUrl) ?>/devices/{id}</code></p>
            <p>
                Single device including <code>primary_ip</code> and <code>cabinet_name</code>.
                Inactive devices are returned if you know the id. Wrapper key: <code>device</code>.
            </p>

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
                </tbody>
            </table>
            <p>Newest <code>updated_at</code> first. Wrapper key: <code>work_orders</code>.</p>

            <h3 class="docs-h3"><span class="docs-method">GET</span> One work order</h3>
            <p class="docs-path"><code><?= App::e($apiUrl) ?>/work_orders/{id}</code></p>
            <p>
                Full work-order row plus <code>items</code> (line items for moves/installs).
                Wrapper keys: <code>work_order</code>, <code>items</code>.
            </p>
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
                <tr><td><code>is_active</code></td><td>0/1</td><td>Soft-delete flag</td></tr>
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
                <tr><td><code>device_type</code></td><td>✓</td><td>✓</td><td>e.g. server, pdu, network_switch</td></tr>
                <tr><td><code>manufacturer</code> / <code>model</code></td><td>✓</td><td>✓</td><td></td></tr>
                <tr><td><code>is_active</code></td><td>✓</td><td>✓</td><td>List is active-only</td></tr>
                <tr><td><code>primary_ip</code></td><td></td><td>✓</td><td>Management / primary address</td></tr>
                </tbody>
            </table>
            <p class="text-muted">
                Detail does not currently return SNMP credentials, iDRAC passwords, or other secret columns.
            </p>

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
                <tr><td><code>401</code></td><td>Missing, invalid, or revoked token</td><td><code>error</code> message</td></tr>
                <tr><td><code>403</code></td><td>Account disabled, or role lacks the resource permission</td><td><code>permission</code> on missing-perm</td></tr>
                <tr><td><code>404</code></td><td>Unknown id, or unknown resource name</td><td><code>resource</code> on unknown path</td></tr>
                <tr><td><code>405</code></td><td>Anything other than GET/HEAD/OPTIONS</td><td><code>hint</code>: Use GET</td></tr>
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
  "<?= App::e($apiUrl) ?>/devices?cabinet_id=12"

curl -sS -H "Authorization: Bearer <?= App::e($tokenPrefix) ?>YOUR_TOKEN" \
  "<?= App::e($apiUrl) ?>/work_orders?status=in_progress"</pre>
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
cabinets = requests.get(base + "/cabinets", headers=headers, timeout=30).json()["cabinets"]
devices = requests.get(base + "/devices", headers=headers, params={"cabinet_id": 12}, timeout=30).json()["devices"]</pre>
                <button type="button" class="btn btn-sm btn-secondary docs-copy" data-copy-target="docs-ex-py">Copy</button>
            </div>
        </div>
    </div>

    <div class="card" id="notes">
        <div class="card-header"><h2>Limits &amp; notes</h2></div>
        <div class="card-body docs-prose">
            <ul>
                <li><strong>Not in v1:</strong> power PDUs/UPS, cooling, cabling, floor plan geometry writes, SNMP live values, users, or creating/updating records.</li>
                <li><strong>Internal APIs</strong> under <code>/api/</code> (except <code>v1.php</code>) stay session-authenticated. Do not point an ITSM robot at them.</li>
                <li><strong>Keep DCIM internal.</strong> Tokens are long-lived secrets. Prefer calling this API from a trusted network or a jump host — do not publish ColdAisle to the internet just to enable integrations.</li>
                <li>List calls are unbounded; large sites should filter (<code>cabinet_id</code>, <code>status</code>) where possible.</li>
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
