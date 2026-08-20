<?php
/**
 * ManageEngine ServiceDesk Plus Cloud (Zoho) — work-order ITSM.
 *
 * Outbound: OAuth 2.0 refresh token → v3 Requests API
 *   POST /app/{portal}/api/v3/requests  (form field input_data = JSON)
 *   POST /app/{portal}/api/v3/requests/{id}/notes
 *   PUT  /app/{portal}/api/v3/requests/{id}
 *
 * Pull: GET the linked request (no public URL). Webhook is optional and off by default.
 * ServiceNow and Jira are not implemented.
 */
declare(strict_types=1);

class SdpCloudService
{
    public const PROVIDER = 'sdp';
    public const MARKER_PREFIX = 'ColdAisle-WO:';
    public const ACCEPT = 'application/vnd.manageengine.sdp.v3+json';

    /** @var array<string,mixed>|null */
    private static ?array $cfgCache = null;

    /**
     * @return array<string,array{label:string,accounts:string,api:string}>
     */
    public static function datacenters(): array
    {
        return [
            'us' => ['label' => 'United States', 'accounts' => 'https://accounts.zoho.com', 'api' => 'https://sdpondemand.manageengine.com'],
            'eu' => ['label' => 'European Union', 'accounts' => 'https://accounts.zoho.eu', 'api' => 'https://sdpondemand.manageengine.eu'],
            'in' => ['label' => 'India', 'accounts' => 'https://accounts.zoho.in', 'api' => 'https://sdpondemand.manageengine.in'],
            'au' => ['label' => 'Australia', 'accounts' => 'https://accounts.zoho.com.au', 'api' => 'https://servicedeskplus.net.au'],
            'jp' => ['label' => 'Japan', 'accounts' => 'https://accounts.zoho.jp', 'api' => 'https://servicedeskplus.jp'],
            'ca' => ['label' => 'Canada', 'accounts' => 'https://accounts.zohocloud.ca', 'api' => 'https://servicedeskplus.ca'],
            'uk' => ['label' => 'United Kingdom', 'accounts' => 'https://accounts.zoho.uk', 'api' => 'https://servicedeskplus.uk'],
            'sa' => ['label' => 'Saudi Arabia', 'accounts' => 'https://accounts.zoho.sa', 'api' => 'https://servicedeskplus.sa'],
            'ae' => ['label' => 'United Arab Emirates', 'accounts' => 'https://accounts.zoho.ae', 'api' => 'https://servicedeskplus.ae'],
            'cn' => ['label' => 'China', 'accounts' => 'https://accounts.zoho.com.cn', 'api' => 'https://servicedeskplus.cn'],
        ];
    }

    public static function oauthScope(): string
    {
        return 'SDPOnDemand.requests.ALL';
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'dc' => 'us',
            'portal' => '',
            'custom_domain' => '',
            'client_id' => '',
            'client_secret' => '',
            'refresh_token' => '',
            'requester_email' => '',
            'request_type' => '',
            'category' => '',
            'site' => '',
            'auto_create' => true,
            'auto_note' => true,
            'close_on_complete' => false,
            'closed_status' => 'Closed',
            'cancelled_status' => 'Cancelled',
            'in_progress_status' => '',
            'inbound_create' => false,
            'inbound_status' => true,
            'webhook_enabled' => false,
            'webhook_secret' => '',
            'tls_verify' => true,
        ];
    }

    /** @return array<string,mixed> */
    public static function config(): array
    {
        if (self::$cfgCache !== null) {
            return self::$cfgCache;
        }
        $d = self::defaults();
        $cfg = $d;
        $cfg['enabled'] = self::get('sdp_enabled', '0') === '1';
        $dc = strtolower(trim(self::get('sdp_dc', 'us')));
        $cfg['dc'] = isset(self::datacenters()[$dc]) ? $dc : 'us';
        $cfg['portal'] = trim(self::get('sdp_portal', ''));
        $cfg['custom_domain'] = rtrim(trim(self::get('sdp_custom_domain', '')), '/');
        $cfg['client_id'] = trim(self::get('sdp_client_id', ''));
        $cfg['client_secret'] = self::secret('sdp_client_secret');
        $cfg['refresh_token'] = self::secret('sdp_refresh_token');
        $cfg['requester_email'] = trim(self::get('sdp_requester_email', ''));
        $cfg['request_type'] = trim(self::get('sdp_request_type', ''));
        $cfg['category'] = trim(self::get('sdp_category', ''));
        $cfg['site'] = trim(self::get('sdp_site', ''));
        $cfg['auto_create'] = self::get('sdp_auto_create', '1') === '1';
        $cfg['auto_note'] = self::get('sdp_auto_note', '1') === '1';
        $cfg['close_on_complete'] = self::get('sdp_close_on_complete', '0') === '1';
        $cfg['closed_status'] = trim(self::get('sdp_closed_status', 'Closed')) ?: 'Closed';
        $cfg['cancelled_status'] = trim(self::get('sdp_cancelled_status', 'Cancelled')) ?: 'Cancelled';
        $cfg['in_progress_status'] = trim(self::get('sdp_in_progress_status', ''));
        $cfg['inbound_create'] = self::get('sdp_inbound_create', '0') === '1';
        $cfg['inbound_status'] = self::get('sdp_inbound_status', '1') === '1';
        $cfg['webhook_enabled'] = self::get('sdp_webhook_enabled', '0') === '1';
        $cfg['webhook_secret'] = self::secret('sdp_webhook_secret');
        $cfg['tls_verify'] = self::get('sdp_tls_verify', '1') === '1';
        self::$cfgCache = $cfg;
        return $cfg;
    }

    public static function resetCache(): void
    {
        self::$cfgCache = null;
    }

    public static function isEnabled(): bool
    {
        $active = '';
        try {
            $active = strtolower(trim((string)SettingsService::get('itsm_provider', '')));
        } catch (Throwable $e) {
            $active = '';
        }
        if ($active === 'sdp') {
            return true;
        }
        if ($active !== '' && $active !== 'sdp') {
            return false;
        }
        return !empty(self::config()['enabled']);
    }

    public static function isConfigured(): bool
    {
        $c = self::config();
        return $c['portal'] !== ''
            && $c['client_id'] !== ''
            && $c['client_secret'] !== ''
            && $c['refresh_token'] !== '';
    }

    public static function autoCreate(): bool
    {
        return self::isEnabled() && self::isConfigured() && !empty(self::config()['auto_create']);
    }

    /** @return array{ready:bool,enabled:bool,label:string,detail:string} */
    public static function status(): array
    {
        $c = self::config();
        if (!$c['enabled']) {
            return ['ready' => false, 'enabled' => false, 'label' => 'Off', 'detail' => 'ServiceDesk Cloud is disabled.'];
        }
        $missing = [];
        if ($c['portal'] === '') {
            $missing[] = 'portal name';
        }
        if ($c['client_id'] === '' || $c['client_secret'] === '') {
            $missing[] = 'OAuth client';
        }
        if ($c['refresh_token'] === '') {
            $missing[] = 'refresh token';
        }
        if ($missing) {
            return [
                'ready' => false,
                'enabled' => true,
                'label' => 'Incomplete',
                'detail' => 'Missing ' . implode(', ', $missing) . '.',
            ];
        }
        $dc = self::datacenters()[$c['dc']]['label'] ?? $c['dc'];
        return [
            'ready' => true,
            'enabled' => true,
            'label' => 'Ready',
            'detail' => 'Portal ' . $c['portal'] . ' · ' . $dc,
        ];
    }

    public static function webhookUrl(): string
    {
        $url = App::url('api/itsm_sdp.php');
        $secret = (string)(self::config()['webhook_secret'] ?? '');
        if ($secret !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'token=' . rawurlencode($secret);
        }
        return $url;
    }

    /**
     * Persist Settings form. Empty secret fields keep the stored value.
     *
     * @param array<string,mixed> $post
     */
    public static function saveFromPost(array $post): void
    {
        $prev = self::config();
        $enabled = !empty($post['sdp_enabled']);
        $dc = strtolower(trim((string)($post['sdp_dc'] ?? 'us')));
        if (!isset(self::datacenters()[$dc])) {
            $dc = 'us';
        }
        $portal = self::normalizePortal((string)($post['sdp_portal'] ?? ''));
        $custom = rtrim(trim((string)($post['sdp_custom_domain'] ?? '')), '/');
        if ($custom !== '' && !preg_match('#^https://[a-z0-9.-]+#i', $custom)) {
            throw new RuntimeException('Custom domain must be an https:// host (no path).');
        }

        $clientId = trim((string)($post['sdp_client_id'] ?? ''));
        $clientSecret = (string)($post['sdp_client_secret'] ?? '');
        $refresh = trim((string)($post['sdp_refresh_token'] ?? ''));
        if ($clientSecret === '') {
            $clientSecret = (string)$prev['client_secret'];
        }
        if ($refresh === '') {
            $refresh = (string)$prev['refresh_token'];
        }
        if (!empty($post['sdp_clear_tokens'])) {
            $refresh = '';
            self::set('sdp_access_token', '', true);
            self::set('sdp_access_expires_at', '0');
            self::set('sdp_api_domain', '');
        }

        $webhookEnabled = !empty($post['sdp_webhook_enabled']);
        $webhook = (string)($post['sdp_webhook_secret'] ?? '');
        if ($webhook === '') {
            $webhook = (string)$prev['webhook_secret'];
        }
        if ($webhookEnabled && (!empty($post['sdp_webhook_regenerate']) || $webhook === '')) {
            $webhook = bin2hex(random_bytes(24));
        }

        $email = trim((string)($post['sdp_requester_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Requester email is not a valid address.');
        }

        self::set('sdp_enabled', $enabled ? '1' : '0');
        self::set('sdp_dc', $dc);
        self::set('sdp_portal', $portal);
        self::set('sdp_custom_domain', $custom);
        self::set('sdp_client_id', $clientId);
        self::set('sdp_client_secret', $clientSecret, true);
        self::set('sdp_refresh_token', $refresh, true);
        self::set('sdp_requester_email', $email);
        self::set('sdp_request_type', trim((string)($post['sdp_request_type'] ?? '')));
        self::set('sdp_category', trim((string)($post['sdp_category'] ?? '')));
        self::set('sdp_site', trim((string)($post['sdp_site'] ?? '')));
        self::set('sdp_auto_create', !empty($post['sdp_auto_create']) ? '1' : '0');
        self::set('sdp_auto_note', !empty($post['sdp_auto_note']) ? '1' : '0');
        self::set('sdp_close_on_complete', !empty($post['sdp_close_on_complete']) ? '1' : '0');
        self::set('sdp_closed_status', trim((string)($post['sdp_closed_status'] ?? 'Closed')) ?: 'Closed');
        self::set('sdp_cancelled_status', trim((string)($post['sdp_cancelled_status'] ?? 'Cancelled')) ?: 'Cancelled');
        self::set('sdp_in_progress_status', trim((string)($post['sdp_in_progress_status'] ?? '')));
        self::set('sdp_inbound_create', !empty($post['sdp_inbound_create']) ? '1' : '0');
        self::set('sdp_inbound_status', !empty($post['sdp_inbound_status']) ? '1' : '0');
        self::set('sdp_webhook_enabled', $webhookEnabled ? '1' : '0');
        self::set('sdp_webhook_secret', $webhook, true);
        self::set('sdp_tls_verify', !empty($post['sdp_tls_verify']) ? '1' : '0');
        self::$cfgCache = null;
    }

    /**
     * Exchange a Zoho API Console Self Client grant code for a refresh token.
     *
     * @param array<string,mixed>|null $override form values not yet saved
     * @return array{ok:bool,summary:string,steps:list<array{name:string,ok:bool,detail:string}>}
     */
    public static function exchangeGrantCode(string $code, ?array $override = null): array
    {
        $steps = [];
        $code = trim($code);
        if ($code === '') {
            return ['ok' => false, 'summary' => 'Paste the grant code from Zoho API Console → Self Client.', 'steps' => []];
        }
        $cfg = self::mergeOverride($override);
        if ($cfg['client_id'] === '' || $cfg['client_secret'] === '') {
            return ['ok' => false, 'summary' => 'Client ID and Client Secret are required before exchanging a code.', 'steps' => []];
        }
        $accounts = self::accountsBase($cfg);
        $steps[] = ['name' => 'Token endpoint', 'ok' => true, 'detail' => $accounts . '/oauth/v2/token'];
        try {
            $resp = self::oauthToken($cfg, [
                'grant_type' => 'authorization_code',
                'client_id' => $cfg['client_id'],
                'client_secret' => $cfg['client_secret'],
                'code' => $code,
            ]);
        } catch (Throwable $e) {
            $steps[] = ['name' => 'Exchange code', 'ok' => false, 'detail' => $e->getMessage()];
            return ['ok' => false, 'summary' => 'Zoho rejected the grant code.', 'steps' => $steps];
        }
        $refresh = trim((string)($resp['refresh_token'] ?? ''));
        $access = trim((string)($resp['access_token'] ?? ''));
        if ($refresh === '') {
            $steps[] = ['name' => 'Refresh token', 'ok' => false, 'detail' => 'Response had no refresh_token. Generate a new Self Client code with scope ' . self::oauthScope() . '.'];
            return ['ok' => false, 'summary' => 'No refresh token in the response.', 'steps' => $steps];
        }
        self::set('sdp_client_id', (string)$cfg['client_id']);
        self::set('sdp_client_secret', (string)$cfg['client_secret'], true);
        self::set('sdp_refresh_token', $refresh, true);
        if ($access !== '') {
            self::storeAccessToken($access, (int)($resp['expires_in'] ?? 3600), (string)($resp['api_domain'] ?? ''));
        }
        $portal = self::normalizePortal((string)($cfg['portal'] ?? ''));
        if ($portal !== '') {
            self::set('sdp_portal', $portal);
        }
        $dc = strtolower((string)($cfg['dc'] ?? 'us'));
        if (isset(self::datacenters()[$dc])) {
            self::set('sdp_dc', $dc);
        }
        self::$cfgCache = null;
        $steps[] = ['name' => 'Refresh token', 'ok' => true, 'detail' => 'Stored encrypted. Access token cached until it expires.'];
        return ['ok' => true, 'summary' => 'Refresh token saved. Use Test connection next.', 'steps' => $steps];
    }

    /**
     * @param array<string,mixed>|null $override
     * @return array{ok:bool,summary:string,steps:list<array{name:string,ok:bool,detail:string}>}
     */
    public static function testConnection(?array $override = null): array
    {
        $steps = [];
        $cfg = self::mergeOverride($override);
        if ($cfg['portal'] === '') {
            return ['ok' => false, 'summary' => 'Portal name is required.', 'steps' => []];
        }
        if ($cfg['client_id'] === '' || $cfg['client_secret'] === '' || $cfg['refresh_token'] === '') {
            return ['ok' => false, 'summary' => 'OAuth client ID, secret, and refresh token are required.', 'steps' => []];
        }
        $steps[] = ['name' => 'Portal', 'ok' => true, 'detail' => $cfg['portal'] . ' · ' . (self::datacenters()[$cfg['dc']]['label'] ?? $cfg['dc'])];
        try {
            $token = self::accessToken($cfg);
            $steps[] = ['name' => 'OAuth access token', 'ok' => true, 'detail' => 'Zoho issued a token (SDPOnDemand.requests).'];
        } catch (Throwable $e) {
            $steps[] = ['name' => 'OAuth access token', 'ok' => false, 'detail' => $e->getMessage()];
            return ['ok' => false, 'summary' => 'Could not refresh the Zoho access token.', 'steps' => $steps];
        }
        try {
            $json = self::api('GET', '/requests', [
                'list_info' => ['row_count' => 1, 'start_index' => 1],
            ], $cfg, $token);
            $ok = self::responseOk($json);
            $n = isset($json['requests']) && is_array($json['requests']) ? count($json['requests']) : 0;
            $detail = $ok
                ? ('Requests API responded (sample row count ' . $n . ').')
                : self::responseError($json);
            $steps[] = ['name' => 'GET /api/v3/requests', 'ok' => $ok, 'detail' => $detail];
            if (!$ok) {
                return ['ok' => false, 'summary' => 'Authenticated, but the Requests API returned an error.', 'steps' => $steps];
            }
        } catch (Throwable $e) {
            $steps[] = ['name' => 'GET /api/v3/requests', 'ok' => false, 'detail' => $e->getMessage()];
            return ['ok' => false, 'summary' => 'Requests API call failed.', 'steps' => $steps];
        }
        return ['ok' => true, 'summary' => 'ServiceDesk Plus Cloud connection works.', 'steps' => $steps];
    }

    public static function woMarker(int $workOrderId): string
    {
        return self::MARKER_PREFIX . $workOrderId;
    }

    /**
     * Create an SDP request from a local work order and store the link.
     *
     * @return array{id:string,display_id:string,url:string}
     */
    public static function createFromWorkOrder(int $workOrderId): array
    {
        if (!self::isEnabled() || !self::isConfigured()) {
            throw new RuntimeException('ServiceDesk Cloud is not configured.');
        }
        $wo = self::loadWorkOrder($workOrderId);
        $existing = trim((string)($wo['itsm_request_id'] ?? ''));
        if ($existing !== '') {
            return [
                'id' => $existing,
                'display_id' => (string)($wo['itsm_display_id'] ?? $wo['change_ticket'] ?? ''),
                'url' => (string)($wo['itsm_url'] ?? self::uiUrl($existing)),
            ];
        }

        $cfg = self::config();
        $subject = self::subjectFromTitle((string)$wo['title']);
        $request = [
            'subject' => $subject,
            'description' => self::buildDescription($wo),
        ];
        if ($cfg['requester_email'] !== '') {
            $request['requester'] = ['email_id' => $cfg['requester_email']];
        }
        if ($cfg['request_type'] !== '') {
            $request['request_type'] = ['name' => $cfg['request_type']];
        }
        if ($cfg['category'] !== '') {
            $request['category'] = ['name' => $cfg['category']];
        }
        if ($cfg['site'] !== '') {
            $request['site'] = ['name' => $cfg['site']];
        }

        $json = self::api('POST', '/requests', ['request' => $request], $cfg);
        if (!self::responseOk($json)) {
            throw new RuntimeException(self::responseError($json));
        }
        $req = is_array($json['request'] ?? null) ? $json['request'] : [];
        $id = (string)($req['id'] ?? '');
        $display = (string)($req['display_id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('ServiceDesk created a request but returned no id.');
        }
        $url = self::uiUrl($id);
        self::storeLink($workOrderId, $wo, $id, $display, $url);
        return ['id' => $id, 'display_id' => $display, 'url' => $url];
    }

    /**
     * Attach an existing SDP request (internal id or display_id).
     *
     * @return array{id:string,display_id:string,url:string}
     */
    public static function linkExisting(int $workOrderId, string $ticket): array
    {
        if (!self::isEnabled() || !self::isConfigured()) {
            throw new RuntimeException('ServiceDesk Cloud is not configured.');
        }
        $ticket = trim($ticket);
        if ($ticket === '') {
            throw new RuntimeException('Enter a ServiceDesk request id or display id.');
        }
        $wo = self::loadWorkOrder($workOrderId);
        $cfg = self::config();
        $req = null;
        if (preg_match('/^\d{6,}$/', $ticket)) {
            try {
                $json = self::api('GET', '/requests/' . rawurlencode($ticket), null, $cfg);
                if (self::responseOk($json) && is_array($json['request'] ?? null)) {
                    $req = $json['request'];
                }
            } catch (Throwable $e) {
                $req = null;
            }
        }
        if ($req === null) {
            $json = self::api('GET', '/requests', [
                'list_info' => [
                    'row_count' => 5,
                    'start_index' => 1,
                    'search_criteria' => [
                        'field' => 'display_id',
                        'condition' => 'is',
                        'value' => $ticket,
                    ],
                ],
            ], $cfg);
            $list = is_array($json['requests'] ?? null) ? $json['requests'] : [];
            $req = is_array($list[0] ?? null) ? $list[0] : null;
        }
        if (!is_array($req) || empty($req['id'])) {
            throw new RuntimeException('No ServiceDesk request matched "' . $ticket . '".');
        }
        $id = (string)$req['id'];
        $display = (string)($req['display_id'] ?? $ticket);
        $url = self::uiUrl($id);
        self::storeLink($workOrderId, $wo, $id, $display, $url);
        return ['id' => $id, 'display_id' => $display, 'url' => $url];
    }

    /**
     * Pull the linked SDP request (outbound GET). No public URL required.
     *
     * @return array{ok:bool,action:string,work_order_id:?int,detail:string}
     */
    public static function pullFromWorkOrder(int $workOrderId): array
    {
        if (!self::isEnabled() || !self::isConfigured()) {
            throw new RuntimeException('ServiceDesk Cloud is not configured.');
        }
        $wo = self::loadWorkOrder($workOrderId);
        $rid = trim((string)($wo['itsm_request_id'] ?? ''));
        if ($rid === '') {
            throw new RuntimeException('This work order is not linked to a ServiceDesk request.');
        }
        $json = self::api('GET', '/requests/' . rawurlencode($rid), null, self::config());
        if (!self::responseOk($json) || !is_array($json['request'] ?? null)) {
            throw new RuntimeException(self::responseError($json));
        }
        return self::handleInboundWebhook(['request' => $json['request']]);
    }

    public static function unlink(int $workOrderId): void
    {
        Database::update('work_orders', [
            'itsm_provider' => null,
            'itsm_request_id' => null,
            'itsm_display_id' => null,
            'itsm_url' => null,
            'itsm_last_error' => null,
            'itsm_last_sync_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'work_order_id = :id', [':id' => $workOrderId]);
    }

    public static function addNote(int $workOrderId, string $html, bool $showRequester = false): void
    {
        $wo = self::loadWorkOrder($workOrderId);
        $rid = trim((string)($wo['itsm_request_id'] ?? ''));
        if ($rid === '') {
            throw new RuntimeException('This work order is not linked to a ServiceDesk request.');
        }
        $json = self::api('POST', '/requests/' . rawurlencode($rid) . '/notes', [
            'request_note' => [
                'description' => $html,
                'show_to_requester' => $showRequester,
                'notify_technician' => false,
                'mark_first_response' => false,
            ],
        ], self::config());
        if (!self::responseOk($json)) {
            throw new RuntimeException(self::responseError($json));
        }
        self::touchSync($workOrderId, null);
    }

    public static function setRemoteStatus(int $workOrderId, string $statusName, string $comment = ''): void
    {
        $wo = self::loadWorkOrder($workOrderId);
        $rid = trim((string)($wo['itsm_request_id'] ?? ''));
        if ($rid === '') {
            throw new RuntimeException('This work order is not linked to a ServiceDesk request.');
        }
        $statusName = trim($statusName);
        if ($statusName === '') {
            return;
        }
        $request = [
            'status' => ['name' => $statusName],
        ];
        if ($comment !== '') {
            $request['status_change_comments'] = mb_substr($comment, 0, 250);
        }
        $json = self::api('PUT', '/requests/' . rawurlencode($rid), ['request' => $request], self::config());
        if (!self::responseOk($json)) {
            throw new RuntimeException(self::responseError($json));
        }
        self::touchSync($workOrderId, null);
    }

    /**
     * After a local status change, push a note and optionally close/cancel in SDP.
     *
     * @param array{user_id?:int,username?:string,display_name?:string}|null $actor
     */
    public static function syncWorkOrderStatus(int $workOrderId, string $from, string $to, ?array $actor = null): void
    {
        if (!self::isEnabled() || !self::isConfigured()) {
            return;
        }
        $wo = self::loadWorkOrder($workOrderId);
        if (trim((string)($wo['itsm_request_id'] ?? '')) === '') {
            return;
        }
        $cfg = self::config();
        $who = (string)($actor['display_name'] ?? $actor['username'] ?? 'ColdAisle');
        $labels = function_exists('work_order_statuses') ? work_order_statuses() : [];
        $fromLab = $labels[$from] ?? $from;
        $toLab = $labels[$to] ?? $to;
        $html = '<p>ColdAisle work order <strong>' . self::h((string)$wo['title']) . '</strong> '
            . '(#' . $workOrderId . ') status: ' . self::h($fromLab) . ' → <strong>' . self::h($toLab) . '</strong>'
            . ' by ' . self::h($who) . '.</p>'
            . '<p><a href="' . self::h(App::url('pages/work_orders.php?id=' . $workOrderId)) . '">Open work order</a></p>';

        $err = null;
        if (!empty($cfg['auto_note'])) {
            try {
                self::addNote($workOrderId, $html, false);
            } catch (Throwable $e) {
                $err = $e->getMessage();
            }
        }
        $remote = '';
        if ($to === 'completed' && !empty($cfg['close_on_complete'])) {
            $remote = (string)$cfg['closed_status'];
        } elseif ($to === 'cancelled' && (string)$cfg['cancelled_status'] !== '') {
            $remote = (string)$cfg['cancelled_status'];
        } elseif ($to === 'in_progress' && (string)$cfg['in_progress_status'] !== '') {
            $remote = (string)$cfg['in_progress_status'];
        }
        if ($remote !== '') {
            try {
                self::setRemoteStatus($workOrderId, $remote, 'ColdAisle work order ' . $toLab);
            } catch (Throwable $e) {
                $err = $err ? ($err . ' ' . $e->getMessage()) : $e->getMessage();
            }
        }
        if ($err !== null) {
            self::touchSync($workOrderId, $err);
            throw new RuntimeException($err);
        }
    }

    /**
     * Best-effort create after a new WO. Failures are stored on the row, not thrown
     * unless $throw is true.
     */
    public static function tryCreateFromWorkOrder(int $workOrderId, bool $throw = false): ?string
    {
        if (!self::autoCreate()) {
            return null;
        }
        try {
            $link = self::createFromWorkOrder($workOrderId);
            $disp = (string)($link['display_id'] ?: $link['id']);
            return $disp !== '' ? $disp : null;
        } catch (Throwable $e) {
            self::touchSync($workOrderId, $e->getMessage());
            App::log('SDP create WO #' . $workOrderId . ': ' . $e->getMessage(), 'warning');
            if ($throw) {
                throw $e;
            }
            return null;
        }
    }

    public static function verifyWebhookToken(?string $got): bool
    {
        $need = (string)(self::config()['webhook_secret'] ?? '');
        if ($need === '' || $got === null || $got === '') {
            return false;
        }
        if (strlen($got) !== strlen($need)) {
            // still compare to keep timing quieter
        }
        return hash_equals($need, $got);
    }

    /**
     * Create or update a work order from an SDP Custom Trigger payload.
     *
     * @param array<string,mixed> $payload
     * @return array{ok:bool,action:string,work_order_id:?int,detail:string}
     */
    public static function handleInboundWebhook(array $payload): array
    {
        $req = self::extractRequest($payload);
        $rid = trim((string)($req['id'] ?? ''));
        $display = trim((string)($req['display_id'] ?? ''));
        $subject = trim((string)($req['subject'] ?? ''));
        $description = (string)($req['description'] ?? '');
        $sdpStatus = self::flattenStatus($req['status'] ?? null);

        if ($rid === '' && $display === '' && $subject === '') {
            return ['ok' => false, 'action' => 'ignored', 'work_order_id' => null, 'detail' => 'Payload had no request id, display id, or subject.'];
        }

        $wo = self::findLinked($rid, $display, $subject . "\n" . $description);
        $cfg = self::config();

        if ($wo) {
            $woId = (int)$wo['work_order_id'];
            $patch = [
                'itsm_provider' => self::PROVIDER,
                'itsm_last_sync_at' => date('Y-m-d H:i:s'),
                'itsm_last_error' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($rid !== '' && trim((string)($wo['itsm_request_id'] ?? '')) === '') {
                $patch['itsm_request_id'] = $rid;
                $patch['itsm_url'] = self::uiUrl($rid);
            }
            if ($display !== '') {
                $patch['itsm_display_id'] = $display;
                if (trim((string)($wo['change_ticket'] ?? '')) === '') {
                    $patch['change_ticket'] = mb_substr($display, 0, 100);
                }
            }
            if ($subject !== '' && (string)$wo['title'] !== $subject) {
                $patch['title'] = mb_substr($subject, 0, 200);
            }
            $newStatus = null;
            if (!empty($cfg['inbound_status']) && $sdpStatus !== '') {
                $newStatus = self::mapInboundStatus($sdpStatus, (string)$wo['status']);
            }
            if ($newStatus !== null && $newStatus !== (string)$wo['status']) {
                $patch['status'] = $newStatus;
                if ($newStatus === 'completed') {
                    $patch['completed_at'] = date('Y-m-d H:i:s');
                } elseif ((string)$wo['status'] === 'completed') {
                    $patch['completed_at'] = null;
                }
            }
            Database::update('work_orders', $patch, 'work_order_id = :id', [':id' => $woId]);
            $detail = 'Updated work order #' . $woId;
            if ($newStatus !== null && $newStatus !== (string)$wo['status']) {
                $detail .= ' (status ' . $wo['status'] . ' → ' . $newStatus . ')';
            }
            return ['ok' => true, 'action' => 'updated', 'work_order_id' => $woId, 'detail' => $detail];
        }

        if (empty($cfg['inbound_create'])) {
            return ['ok' => true, 'action' => 'ignored', 'work_order_id' => null, 'detail' => 'No matching work order; inbound create is off.'];
        }
        if ($subject === '') {
            $subject = $display !== '' ? ('ServiceDesk #' . $display) : ('ServiceDesk request ' . $rid);
        }
        $status = 'planned';
        if ($sdpStatus !== '') {
            $status = self::mapInboundStatus($sdpStatus, 'draft') ?? 'planned';
            if ($status === 'draft') {
                $status = 'planned';
            }
        }
        $notes = trim(html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (strlen($notes) > 4000) {
            $notes = substr($notes, 0, 3997) . '...';
        }
        $now = date('Y-m-d H:i:s');
        $checklist = function_exists('work_order_default_checklist')
            ? work_order_checklist_encode(work_order_default_checklist('other'))
            : '[]';
        $woId = (int)Database::insert('work_orders', [
            'title' => mb_substr($subject, 0, 200),
            'work_type' => 'other',
            'status' => $status,
            'change_ticket' => $display !== '' ? mb_substr($display, 0, 100) : null,
            'notes' => $notes !== '' ? $notes : null,
            'checklist_json' => $checklist,
            'itsm_provider' => self::PROVIDER,
            'itsm_request_id' => $rid !== '' ? $rid : null,
            'itsm_display_id' => $display !== '' ? $display : null,
            'itsm_url' => $rid !== '' ? self::uiUrl($rid) : null,
            'itsm_last_sync_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'completed_at' => $status === 'completed' ? $now : null,
        ]);
        return [
            'ok' => true,
            'action' => 'created',
            'work_order_id' => $woId,
            'detail' => 'Created work order #' . $woId . ' from ServiceDesk ' . ($display !== '' ? '#' . $display : $rid),
        ];
    }

    public static function uiUrl(string $requestId): string
    {
        $base = self::apiBase(self::config());
        $portal = rawurlencode((string)self::config()['portal']);
        $id = rawurlencode($requestId);
        return $base . '/app/' . $portal . '/ui/requests/' . $id . '/details';
    }

    public static function isLinked(array $wo): bool
    {
        return trim((string)($wo['itsm_request_id'] ?? '')) !== ''
            || ((string)($wo['itsm_provider'] ?? '') === self::PROVIDER && trim((string)($wo['itsm_display_id'] ?? '')) !== '');
    }

    // --- internals ---

    /** @param array<string,mixed>|null $override */
    private static function mergeOverride(?array $override): array
    {
        $cfg = self::config();
        if (!is_array($override)) {
            return $cfg;
        }
        foreach (['dc', 'portal', 'custom_domain', 'client_id', 'requester_email'] as $k) {
            if (isset($override[$k]) && is_string($override[$k]) && trim($override[$k]) !== '') {
                $cfg[$k] = $k === 'portal'
                    ? self::normalizePortal($override[$k])
                    : trim($override[$k]);
            }
        }
        if (isset($override['client_secret']) && is_string($override['client_secret']) && $override['client_secret'] !== '') {
            $cfg['client_secret'] = $override['client_secret'];
        }
        if (isset($override['refresh_token']) && is_string($override['refresh_token']) && trim($override['refresh_token']) !== '') {
            $cfg['refresh_token'] = trim($override['refresh_token']);
        }
        if (array_key_exists('tls_verify', $override)) {
            $cfg['tls_verify'] = !empty($override['tls_verify']);
        }
        $dc = strtolower((string)$cfg['dc']);
        $cfg['dc'] = isset(self::datacenters()[$dc]) ? $dc : 'us';
        return $cfg;
    }

    public static function normalizePortal(string $raw): string
    {
        $p = trim($raw);
        $p = preg_replace('#^https?://#i', '', $p) ?? $p;
        if (preg_match('#(?:^|/)app/([^/]+)#i', $p, $m)) {
            $p = $m[1];
        } elseif (str_contains($p, '/')) {
            $p = basename(rtrim($p, '/'));
        }
        $p = preg_replace('/[^a-zA-Z0-9._-]/', '', $p) ?? $p;
        return $p;
    }

    /** @return array<string,mixed> */
    private static function loadWorkOrder(int $id): array
    {
        $wo = Database::fetchOne('SELECT * FROM work_orders WHERE work_order_id = ?', [$id]);
        if (!$wo) {
            throw new RuntimeException('Work order not found.');
        }
        return $wo;
    }

    /** @param array<string,mixed> $wo */
    private static function storeLink(int $workOrderId, array $wo, string $id, string $display, string $url): void
    {
        $patch = [
            'itsm_provider' => self::PROVIDER,
            'itsm_request_id' => $id,
            'itsm_display_id' => $display !== '' ? $display : null,
            'itsm_url' => $url,
            'itsm_last_sync_at' => date('Y-m-d H:i:s'),
            'itsm_last_error' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if (trim((string)($wo['change_ticket'] ?? '')) === '' && $display !== '') {
            $patch['change_ticket'] = mb_substr($display, 0, 100);
        }
        Database::update('work_orders', $patch, 'work_order_id = :id', [':id' => $workOrderId]);
    }

    private static function touchSync(int $workOrderId, ?string $error): void
    {
        try {
            Database::update('work_orders', [
                'itsm_last_sync_at' => date('Y-m-d H:i:s'),
                'itsm_last_error' => $error !== null ? mb_substr($error, 0, 500) : null,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'work_order_id = :id', [':id' => $workOrderId]);
        } catch (Throwable $e) {
            // columns may be missing on a half-updated host
        }
    }

    /** @param array<string,mixed> $wo */
    private static function buildDescription(array $wo): string
    {
        $id = (int)$wo['work_order_id'];
        $types = function_exists('work_order_types') ? work_order_types() : [];
        $statuses = function_exists('work_order_statuses') ? work_order_statuses() : [];
        $type = $types[(string)($wo['work_type'] ?? '')] ?? (string)($wo['work_type'] ?? '');
        $st = $statuses[(string)($wo['status'] ?? '')] ?? (string)($wo['status'] ?? '');
        $link = App::url('pages/work_orders.php?id=' . $id);
        $parts = [];
        $parts[] = '<p>' . self::h(self::woMarker($id)) . ' — created from ColdAisle.</p>';
        $parts[] = '<p><a href="' . self::h($link) . '">Open work order in ColdAisle</a></p>';
        $parts[] = '<p>Type: ' . self::h($type) . '<br>Status: ' . self::h($st);
        if (!empty($wo['scheduled_date'])) {
            $parts[count($parts) - 1] .= '<br>Scheduled: ' . self::h((string)$wo['scheduled_date']);
        }
        $parts[count($parts) - 1] .= '</p>';
        $notes = trim((string)($wo['notes'] ?? ''));
        if ($notes !== '') {
            $parts[] = '<p><strong>Notes</strong></p><p>' . nl2br(self::h($notes)) . '</p>';
        }
        try {
            $items = Database::fetchAll(
                'SELECT i.*, d.label AS device_label,
                        fc.name AS from_cabinet_name, tc.name AS to_cabinet_name
                 FROM work_order_items i
                 INNER JOIN devices d ON d.device_id = i.device_id
                 LEFT JOIN cabinets fc ON fc.cabinet_id = i.from_cabinet_id
                 LEFT JOIN cabinets tc ON tc.cabinet_id = i.to_cabinet_id
                 WHERE i.work_order_id = ?
                 ORDER BY i.sort_order, i.item_id',
                [$id]
            );
        } catch (Throwable $e) {
            $items = [];
        }
        if ($items) {
            $rows = '';
            foreach ($items as $it) {
                $from = (string)($it['from_cabinet_name'] ?? '—');
                if ($it['from_position_u'] !== null && $it['from_position_u'] !== '') {
                    $from .= ' U' . (int)$it['from_position_u'];
                }
                $to = (string)($it['to_cabinet_name'] ?? '—');
                if ($it['to_position_u'] !== null && $it['to_position_u'] !== '') {
                    $to .= ' U' . (int)$it['to_position_u'];
                }
                $rows .= '<tr><td>' . self::h((string)($it['device_label'] ?? '')) . '</td>'
                    . '<td>' . self::h($from) . '</td><td>' . self::h($to) . '</td></tr>';
            }
            $parts[] = '<p><strong>Devices</strong></p><table border="1" cellpadding="4" cellspacing="0">'
                . '<tr><th>Device</th><th>From</th><th>To</th></tr>' . $rows . '</table>';
        }
        return implode('', $parts);
    }

    private static function subjectFromTitle(string $title): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $title) ?? $title);
        if ($s === '') {
            $s = 'ColdAisle work order';
        }
        if (strlen($s) > 250) {
            $s = substr($s, 0, 247) . '...';
        }
        return $s;
    }

    private static function mapInboundStatus(string $sdpStatus, string $currentWo): ?string
    {
        $s = strtolower(trim($sdpStatus));
        $s = str_replace(['_', '-'], ' ', $s);
        $closed = ['closed', 'resolved', 'completed', 'complete', 'close'];
        $cancel = ['cancelled', 'canceled', 'rejected', 'cancel'];
        $progress = ['in progress', 'on hold', 'onhold', 'assigned', 'open in progress', 'work in progress'];
        $open = ['open', 'open unassigned', 'unassigned', 'new'];

        $target = null;
        foreach ($closed as $k) {
            if ($s === $k || str_contains($s, $k)) {
                $target = 'completed';
                break;
            }
        }
        if ($target === null) {
            foreach ($cancel as $k) {
                if ($s === $k || str_contains($s, $k)) {
                    $target = 'cancelled';
                    break;
                }
            }
        }
        if ($target === null) {
            foreach ($progress as $k) {
                if ($s === $k || str_contains($s, $k)) {
                    $target = 'in_progress';
                    break;
                }
            }
        }
        if ($target === null) {
            foreach ($open as $k) {
                if ($s === $k) {
                    $target = $currentWo === 'in_progress' ? 'in_progress' : 'planned';
                    break;
                }
            }
        }
        return $target;
    }

    private static function findLinked(string $rid, string $display, string $haystack): ?array
    {
        try {
            if ($rid !== '') {
                $row = Database::fetchOne(
                    'SELECT * FROM work_orders WHERE itsm_request_id = ?',
                    [$rid]
                );
                if ($row) {
                    return $row;
                }
            }
            if ($display !== '') {
                $row = Database::fetchOne(
                    'SELECT * FROM work_orders WHERE itsm_display_id = ? OR change_ticket = ?',
                    [$display, $display]
                );
                if ($row) {
                    return $row;
                }
            }
            if (preg_match('/' . preg_quote(self::MARKER_PREFIX, '/') . '(\d+)/', $haystack, $m)) {
                $row = Database::fetchOne(
                    'SELECT * FROM work_orders WHERE work_order_id = ?',
                    [(int)$m[1]]
                );
                if ($row) {
                    return $row;
                }
            }
        } catch (Throwable $e) {
            return null;
        }
        return null;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function extractRequest(array $payload): array
    {
        foreach (['request', 'Request', 'data', 'payload'] as $k) {
            if (isset($payload[$k]) && is_array($payload[$k])) {
                $inner = $payload[$k];
                if (isset($inner['request']) && is_array($inner['request'])) {
                    return $inner['request'];
                }
                return $inner;
            }
        }
        if (isset($payload['input_data']) && is_string($payload['input_data'])) {
            $decoded = json_decode($payload['input_data'], true);
            if (is_array($decoded)) {
                return self::extractRequest($decoded);
            }
        }
        return $payload;
    }

    private static function flattenStatus($status): string
    {
        if (is_array($status)) {
            foreach (['internal_name', 'name', 'display_name', 'value'] as $k) {
                if (!empty($status[$k]) && is_string($status[$k])) {
                    return $status[$k];
                }
            }
            return '';
        }
        return is_string($status) ? $status : '';
    }

    /** @param array<string,mixed> $cfg */
    private static function accountsBase(array $cfg): string
    {
        $dc = self::datacenters()[$cfg['dc'] ?? 'us'] ?? self::datacenters()['us'];
        return rtrim((string)$dc['accounts'], '/');
    }

    /** @param array<string,mixed> $cfg */
    private static function apiBase(array $cfg): string
    {
        $custom = rtrim((string)($cfg['custom_domain'] ?? ''), '/');
        if ($custom !== '') {
            return $custom;
        }
        $stored = rtrim(trim(self::get('sdp_api_domain', '')), '/');
        if ($stored !== '' && preg_match('#^https://#i', $stored)) {
            return $stored;
        }
        $dc = self::datacenters()[$cfg['dc'] ?? 'us'] ?? self::datacenters()['us'];
        return rtrim((string)$dc['api'], '/');
    }

    /**
     * @param array<string,mixed> $cfg
     */
    private static function accessToken(array $cfg): string
    {
        $cached = self::secret('sdp_access_token');
        $exp = (int)self::get('sdp_access_expires_at', '0');
        if ($cached !== '' && $exp > (time() + 60)) {
            return $cached;
        }
        $resp = self::oauthToken($cfg, [
            'grant_type' => 'refresh_token',
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'refresh_token' => $cfg['refresh_token'],
        ]);
        $token = trim((string)($resp['access_token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('Zoho did not return an access_token.');
        }
        self::storeAccessToken($token, (int)($resp['expires_in'] ?? 3600), (string)($resp['api_domain'] ?? ''));
        return $token;
    }

    private static function storeAccessToken(string $token, int $expiresIn, string $apiDomain): void
    {
        $expiresIn = max(60, $expiresIn);
        self::set('sdp_access_token', $token, true);
        self::set('sdp_access_expires_at', (string)(time() + $expiresIn));
        if ($apiDomain !== '' && preg_match('#^https://#i', $apiDomain)) {
            self::set('sdp_api_domain', rtrim($apiDomain, '/'));
        }
    }

    /**
     * @param array<string,mixed> $cfg
     * @param array<string,string> $fields
     * @return array<string,mixed>
     */
    private static function oauthToken(array $cfg, array $fields): array
    {
        $url = self::accountsBase($cfg) . '/oauth/v2/token';
        $raw = self::http('POST', $url, http_build_query($fields), [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], $cfg);
        $json = json_decode($raw['body'], true);
        if (!is_array($json)) {
            throw new RuntimeException('Zoho token endpoint returned non-JSON (HTTP ' . $raw['code'] . ').');
        }
        if (!empty($json['error'])) {
            $msg = (string)$json['error'];
            if (!empty($json['error_description'])) {
                $msg .= ': ' . $json['error_description'];
            }
            throw new RuntimeException($msg);
        }
        if ($raw['code'] >= 400) {
            throw new RuntimeException('Zoho token HTTP ' . $raw['code']);
        }
        return $json;
    }

    /**
     * @param array<string,mixed>|null $inputData
     * @param array<string,mixed> $cfg
     * @return array<string,mixed>
     */
    private static function api(string $method, string $path, ?array $inputData, array $cfg, ?string $token = null): array
    {
        $token = $token ?? self::accessToken($cfg);
        $portal = rawurlencode((string)$cfg['portal']);
        $url = self::apiBase($cfg) . '/app/' . $portal . '/api/v3' . $path;
        $headers = [
            'Accept: ' . self::ACCEPT,
            'Authorization: Zoho-oauthtoken ' . $token,
            'Content-Type: application/x-www-form-urlencoded',
        ];
        $body = null;
        if ($inputData !== null) {
            $encoded = json_encode($inputData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($method === 'GET') {
                $url .= (str_contains($url, '?') ? '&' : '?') . 'input_data=' . rawurlencode((string)$encoded);
            } else {
                $body = http_build_query(['input_data' => $encoded]);
            }
        }
        $raw = self::http($method, $url, $body, $headers, $cfg);
        if ($raw['code'] === 401) {
            self::set('sdp_access_expires_at', '0');
            $token = self::accessToken($cfg);
            $headers[1] = 'Authorization: Zoho-oauthtoken ' . $token;
            $raw = self::http($method, $url, $body, $headers, $cfg);
        }
        $json = json_decode($raw['body'], true);
        if (!is_array($json)) {
            $snip = trim(substr($raw['body'], 0, 180));
            throw new RuntimeException('ServiceDesk HTTP ' . $raw['code'] . ($snip !== '' ? ': ' . $snip : ''));
        }
        return $json;
    }

    /** @param array<string,mixed> $json */
    private static function responseOk(array $json): bool
    {
        $rs = $json['response_status'] ?? null;
        if (is_array($rs) && array_is_list($rs)) {
            $rs = $rs[0] ?? null;
        }
        if (is_array($rs)) {
            $code = (int)($rs['status_code'] ?? 0);
            $st = strtolower((string)($rs['status'] ?? ''));
            return $code === 2000 || $st === 'success';
        }
        return isset($json['request']) || isset($json['requests']) || isset($json['request_note']);
    }

    /** @param array<string,mixed> $json */
    private static function responseError(array $json): string
    {
        $rs = $json['response_status'] ?? null;
        if (is_array($rs) && array_is_list($rs)) {
            $rs = $rs[0] ?? null;
        }
        if (is_array($rs)) {
            $msgs = $rs['messages'] ?? null;
            if (is_array($msgs) && isset($msgs[0]) && is_array($msgs[0])) {
                $m = (string)($msgs[0]['message'] ?? $msgs[0]['status'] ?? '');
                if ($m !== '') {
                    return $m;
                }
            }
            if (!empty($rs['message'])) {
                return (string)$rs['message'];
            }
            if (!empty($rs['status'])) {
                return 'ServiceDesk: ' . $rs['status'] . ' (' . ($rs['status_code'] ?? '?') . ')';
            }
        }
        return 'ServiceDesk API error.';
    }

    /**
     * @param list<string> $headers
     * @param array<string,mixed> $cfg
     * @return array{code:int,body:string}
     */
    private static function http(string $method, string $url, ?string $body, array $headers, array $cfg): array
    {
        return ItsmHttp::request(
            $method,
            $url,
            $body,
            $headers,
            !empty($cfg['tls_verify']),
            'ColdAisle-SDP'
        );
    }

    private static function get(string $key, string $default = ''): string
    {
        try {
            $v = SettingsService::get($key, $default);
            return $v === null ? $default : (string)$v;
        } catch (Throwable $e) {
            return $default;
        }
    }

    private static function secret(string $key): string
    {
        $raw = self::get($key, '');
        if ($raw === '') {
            return '';
        }
        $plain = Crypto::decryptQuiet($raw);
        return $plain !== null ? $plain : '';
    }

    private static function set(string $key, string $value, bool $encrypt = false): void
    {
        if ($encrypt) {
            if ($value === '') {
                SettingsService::set($key, '', 'itsm');
                return;
            }
            $sealed = Crypto::encrypt($value);
            SettingsService::set($key, $sealed ?? $value, 'itsm');
            return;
        }
        SettingsService::set($key, $value, 'itsm');
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
