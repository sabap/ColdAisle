<?php
/**
 * Work-order ticketing facade. One active provider; outbound HTTPS + pull.
 * Providers: ServiceDesk Cloud, ServiceNow, Zendesk, Jira, Freshservice.
 */
declare(strict_types=1);

class ItsmService
{
    public const MARKER_PREFIX = 'ColdAisle-WO:';

    /**
     * @return array<string,string>
     */
    public static function providers(): array
    {
        return [
            'sdp' => 'ManageEngine ServiceDesk Cloud',
            'servicenow' => 'ServiceNow',
            'zendesk' => 'Zendesk',
            'jira' => 'Jira Cloud / Data Center',
            'freshservice' => 'Freshservice',
        ];
    }

    public static function activeId(): string
    {
        $p = strtolower(trim(self::settingGet('itsm_provider', '')));
        if (isset(self::providers()[$p])) {
            return $p;
        }
        if (class_exists('SdpCloudService') && SdpCloudService::isEnabled()) {
            return 'sdp';
        }
        return '';
    }

    public static function label(?string $id = null): string
    {
        $id = $id ?? self::activeId();
        return self::providers()[$id] ?? 'Ticketing';
    }

    public static function isReady(): bool
    {
        $id = self::activeId();
        if ($id === '') {
            return false;
        }
        return self::providerConfigured($id);
    }

    public static function autoCreate(): bool
    {
        if (!self::isReady()) {
            return false;
        }
        $id = self::activeId();
        if ($id === 'sdp') {
            return class_exists('SdpCloudService') && SdpCloudService::autoCreate();
        }
        $prefix = self::prefix($id);
        return self::settingGet($prefix . 'auto_create', '1') === '1';
    }

    /** @return array{ready:bool,enabled:bool,label:string,detail:string} */
    public static function status(): array
    {
        $id = self::activeId();
        if ($id === '') {
            return [
                'ready' => false,
                'enabled' => false,
                'label' => 'Off',
                'detail' => 'No ticketing system selected. Work orders stay internal.',
            ];
        }
        $name = self::label($id);
        if (!self::providerConfigured($id)) {
            return [
                'ready' => false,
                'enabled' => true,
                'label' => 'Incomplete',
                'detail' => $name . ' is selected but credentials are incomplete.',
            ];
        }
        return [
            'ready' => true,
            'enabled' => true,
            'label' => $name,
            'detail' => $name . ' — outbound HTTPS only (no public URL required).',
        ];
    }

    /**
     * @param array<string,mixed> $post
     */
    public static function saveFromPost(array $post): void
    {
        $id = strtolower(trim((string)($post['itsm_provider'] ?? '')));
        if ($id !== '' && !isset(self::providers()[$id])) {
            throw new RuntimeException('Unknown ticketing system.');
        }
        self::settingSet('itsm_provider', $id);
        if ($id === 'sdp') {
            if (!class_exists('SdpCloudService')) {
                throw new RuntimeException('SdpCloudService is not installed.');
            }
            $post['sdp_enabled'] = '1';
            SdpCloudService::saveFromPost($post);
            return;
        }
        if (class_exists('SdpCloudService')) {
            self::settingSet('sdp_enabled', '0');
        }
        if ($id === 'servicenow') {
            ItsmSnowService::saveFromPost($post);
        } elseif ($id === 'zendesk') {
            ItsmZendeskService::saveFromPost($post);
        } elseif ($id === 'jira') {
            ItsmJiraService::saveFromPost($post);
        } elseif ($id === 'freshservice') {
            ItsmFreshserviceService::saveFromPost($post);
        }
    }

    /**
     * @param array<string,mixed>|null $override
     * @return array{ok:bool,summary:string,steps:list<array{name:string,ok:bool,detail:string}>}
     */
    public static function testConnection(?string $provider = null, ?array $override = null): array
    {
        $id = strtolower(trim((string)($provider ?: ($override['itsm_provider'] ?? self::activeId()))));
        if ($id === '') {
            return ['ok' => false, 'summary' => 'Select a ticketing system first.', 'steps' => []];
        }
        return match ($id) {
            'sdp' => class_exists('SdpCloudService')
                ? SdpCloudService::testConnection($override)
                : ['ok' => false, 'summary' => 'ServiceDesk Cloud is not deployed.', 'steps' => []],
            'servicenow' => ItsmSnowService::testConnection($override),
            'zendesk' => ItsmZendeskService::testConnection($override),
            'jira' => ItsmJiraService::testConnection($override),
            'freshservice' => ItsmFreshserviceService::testConnection($override),
            default => ['ok' => false, 'summary' => 'Unknown ticketing system.', 'steps' => []],
        };
    }

    /**
     * @return array{id:string,display_id:string,url:string}
     */
    public static function createFromWorkOrder(int $workOrderId): array
    {
        self::assertReady();
        return match (self::activeId()) {
            'sdp' => SdpCloudService::createFromWorkOrder($workOrderId),
            'servicenow' => ItsmSnowService::createFromWorkOrder($workOrderId),
            'zendesk' => ItsmZendeskService::createFromWorkOrder($workOrderId),
            'jira' => ItsmJiraService::createFromWorkOrder($workOrderId),
            'freshservice' => ItsmFreshserviceService::createFromWorkOrder($workOrderId),
            default => throw new RuntimeException('No ticketing system selected.'),
        };
    }

    /**
     * @return array{id:string,display_id:string,url:string}
     */
    public static function linkExisting(int $workOrderId, string $ticket): array
    {
        self::assertReady();
        return match (self::activeId()) {
            'sdp' => SdpCloudService::linkExisting($workOrderId, $ticket),
            'servicenow' => ItsmSnowService::linkExisting($workOrderId, $ticket),
            'zendesk' => ItsmZendeskService::linkExisting($workOrderId, $ticket),
            'jira' => ItsmJiraService::linkExisting($workOrderId, $ticket),
            'freshservice' => ItsmFreshserviceService::linkExisting($workOrderId, $ticket),
            default => throw new RuntimeException('No ticketing system selected.'),
        };
    }

    /** @return array{ok:bool,action:string,work_order_id:?int,detail:string} */
    public static function pullFromWorkOrder(int $workOrderId): array
    {
        self::assertReady();
        return match (self::activeId()) {
            'sdp' => SdpCloudService::pullFromWorkOrder($workOrderId),
            'servicenow' => ItsmSnowService::pullFromWorkOrder($workOrderId),
            'zendesk' => ItsmZendeskService::pullFromWorkOrder($workOrderId),
            'jira' => ItsmJiraService::pullFromWorkOrder($workOrderId),
            'freshservice' => ItsmFreshserviceService::pullFromWorkOrder($workOrderId),
            default => throw new RuntimeException('No ticketing system selected.'),
        };
    }

    public static function addNote(int $workOrderId, string $html, bool $showRequester = false): void
    {
        self::assertReady();
        match (self::activeId()) {
            'sdp' => SdpCloudService::addNote($workOrderId, $html, $showRequester),
            'servicenow' => ItsmSnowService::addNote($workOrderId, $html),
            'zendesk' => ItsmZendeskService::addNote($workOrderId, $html),
            'jira' => ItsmJiraService::addNote($workOrderId, $html),
            'freshservice' => ItsmFreshserviceService::addNote($workOrderId, $html),
            default => throw new RuntimeException('No ticketing system selected.'),
        };
    }

    /**
     * @param array{user_id?:int,username?:string,display_name?:string}|null $actor
     */
    public static function syncWorkOrderStatus(int $workOrderId, string $from, string $to, ?array $actor = null): void
    {
        if (!self::isReady()) {
            return;
        }
        if (self::activeId() === 'sdp' && class_exists('SdpCloudService')) {
            SdpCloudService::syncWorkOrderStatus($workOrderId, $from, $to, $actor);
            return;
        }
        $wo = self::loadWorkOrder($workOrderId);
        if (trim((string)($wo['itsm_request_id'] ?? '')) === '') {
            return;
        }
        $who = (string)($actor['display_name'] ?? $actor['username'] ?? 'ColdAisle');
        $labels = function_exists('work_order_statuses') ? work_order_statuses() : [];
        $fromLab = $labels[$from] ?? $from;
        $toLab = $labels[$to] ?? $to;
        $html = '<p>ColdAisle work order <strong>' . self::h((string)$wo['title']) . '</strong> '
            . '(#' . $workOrderId . ') status: ' . self::h($fromLab) . ' → <strong>' . self::h($toLab) . '</strong>'
            . ' by ' . self::h($who) . '.</p>'
            . '<p><a href="' . self::h(App::url('pages/work_orders.php?id=' . $workOrderId)) . '">Open work order</a></p>';
        $prefix = self::prefix(self::activeId());
        $err = null;
        if (self::settingGet($prefix . 'auto_note', '1') === '1') {
            try {
                self::addNote($workOrderId, $html, false);
            } catch (Throwable $e) {
                $err = $e->getMessage();
            }
        }
        $pushStatus = ($to === 'cancelled')
            || ($to === 'in_progress')
            || ($to === 'completed' && self::settingGet($prefix . 'close_on_complete', '0') === '1');
        if ($pushStatus) {
            try {
                self::setRemoteStatus($workOrderId, $to);
            } catch (Throwable $e) {
                $err = $err ? ($err . ' ' . $e->getMessage()) : $e->getMessage();
            }
        }
        if ($err !== null) {
            self::touchSync($workOrderId, $err);
            throw new RuntimeException($err);
        }
    }

    public static function setRemoteStatus(int $workOrderId, string $woStatus): void
    {
        match (self::activeId()) {
            'servicenow' => ItsmSnowService::setRemoteStatus($workOrderId, $woStatus),
            'zendesk' => ItsmZendeskService::setRemoteStatus($workOrderId, $woStatus),
            'jira' => ItsmJiraService::setRemoteStatus($workOrderId, $woStatus),
            'freshservice' => ItsmFreshserviceService::setRemoteStatus($workOrderId, $woStatus),
            'sdp' => null,
            default => null,
        };
    }

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
            App::log('ITSM create WO #' . $workOrderId . ': ' . $e->getMessage(), 'warning');
            if ($throw) {
                throw $e;
            }
            return null;
        }
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

    public static function woMarker(int $workOrderId): string
    {
        return self::MARKER_PREFIX . $workOrderId;
    }

    /** @return array<string,mixed> */
    public static function loadWorkOrder(int $id): array
    {
        $wo = Database::fetchOne('SELECT * FROM work_orders WHERE work_order_id = ?', [$id]);
        if (!$wo) {
            throw new RuntimeException('Work order not found.');
        }
        return $wo;
    }

    /**
     * @param array<string,mixed> $wo
     */
    public static function storeLink(int $workOrderId, array $wo, string $id, string $display, string $url, ?string $provider = null): void
    {
        $patch = [
            'itsm_provider' => $provider ?? self::activeId(),
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

    public static function touchSync(int $workOrderId, ?string $error): void
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

    /**
     * Apply a pulled remote ticket onto the local work order (no inventory apply).
     *
     * @param array{id?:string,display_id?:string,url?:string,subject?:string,status?:string} $remote
     * @return array{ok:bool,action:string,work_order_id:?int,detail:string}
     */
    public static function applyRemote(int $workOrderId, array $remote): array
    {
        $wo = self::loadWorkOrder($workOrderId);
        $rid = trim((string)($remote['id'] ?? $wo['itsm_request_id'] ?? ''));
        $display = trim((string)($remote['display_id'] ?? ''));
        $subject = trim((string)($remote['subject'] ?? ''));
        $sdpStatus = trim((string)($remote['status'] ?? ''));
        $url = trim((string)($remote['url'] ?? ''));
        $prefix = self::prefix(self::activeId());
        $mapStatus = self::settingGet($prefix . 'inbound_status', '1') === '1';

        $patch = [
            'itsm_provider' => self::activeId(),
            'itsm_last_sync_at' => date('Y-m-d H:i:s'),
            'itsm_last_error' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($rid !== '') {
            $patch['itsm_request_id'] = $rid;
        }
        if ($url !== '') {
            $patch['itsm_url'] = $url;
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
        if ($mapStatus && $sdpStatus !== '') {
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
        Database::update('work_orders', $patch, 'work_order_id = :id', [':id' => $workOrderId]);
        $detail = 'Updated work order #' . $workOrderId;
        if ($newStatus !== null && $newStatus !== (string)$wo['status']) {
            $detail .= ' (status ' . $wo['status'] . ' → ' . $newStatus . ')';
        }
        return ['ok' => true, 'action' => 'updated', 'work_order_id' => $workOrderId, 'detail' => $detail];
    }

    /** @param array<string,mixed> $wo */
    public static function buildDescription(array $wo): string
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

    public static function subjectFromTitle(string $title): string
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

    public static function htmlToText(string $html): string
    {
        $t = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/\n{3,}/", "\n\n", $t) ?? $t;
        return trim($t);
    }

    public static function mapInboundStatus(string $remoteStatus, string $currentWo): ?string
    {
        $s = strtolower(trim($remoteStatus));
        $s = str_replace(['_', '-'], ' ', $s);
        $closed = ['closed', 'resolved', 'completed', 'complete', 'close', 'done', 'solved', '7', '5', '6', '3'];
        $cancel = ['cancelled', 'canceled', 'rejected', 'cancel', '8', '4'];
        $progress = ['in progress', 'on hold', 'onhold', 'assigned', 'open', 'pending', 'hold', 'work in progress', 'implement', '2'];
        $open = ['new', 'open unassigned', 'unassigned', '1', 'draft', 'assess', 'authorize', 'scheduled'];

        foreach ($closed as $k) {
            if ($s === $k || ($k !== '3' && $k !== '5' && $k !== '6' && $k !== '7' && str_contains($s, $k))) {
                if (in_array($k, ['3', '5', '6', '7'], true) && $s !== $k) {
                    continue;
                }
                return 'completed';
            }
        }
        foreach ($cancel as $k) {
            if ($s === $k || (!is_numeric($k) && str_contains($s, $k))) {
                return 'cancelled';
            }
        }
        foreach ($progress as $k) {
            if ($s === $k || (!is_numeric($k) && str_contains($s, $k))) {
                return 'in_progress';
            }
        }
        foreach ($open as $k) {
            if ($s === $k) {
                return $currentWo === 'in_progress' ? 'in_progress' : 'planned';
            }
        }
        return null;
    }

    public static function keepSecret(string $posted, string $existing): string
    {
        return $posted !== '' ? $posted : $existing;
    }

    public static function settingGet(string $key, string $default = ''): string
    {
        try {
            $v = SettingsService::get($key, $default);
            return $v === null ? $default : (string)$v;
        } catch (Throwable $e) {
            return $default;
        }
    }

    public static function settingSet(string $key, string $value, bool $encrypt = false): void
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

    public static function settingSecret(string $key): string
    {
        $raw = self::settingGet($key, '');
        if ($raw === '') {
            return '';
        }
        $plain = Crypto::decryptQuiet($raw);
        return $plain !== null ? $plain : '';
    }

    public static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function isLinked(array $wo): bool
    {
        return trim((string)($wo['itsm_request_id'] ?? '')) !== ''
            || trim((string)($wo['itsm_display_id'] ?? '')) !== '';
    }

    private static function assertReady(): void
    {
        if (!self::isReady()) {
            throw new RuntimeException('Ticketing is not configured. Settings → Ticketing.');
        }
    }

    private static function providerConfigured(string $id): bool
    {
        return match ($id) {
            'sdp' => class_exists('SdpCloudService') && SdpCloudService::isConfigured(),
            'servicenow' => ItsmSnowService::isConfigured(),
            'zendesk' => ItsmZendeskService::isConfigured(),
            'jira' => ItsmJiraService::isConfigured(),
            'freshservice' => ItsmFreshserviceService::isConfigured(),
            default => false,
        };
    }

    private static function prefix(string $id): string
    {
        return match ($id) {
            'servicenow' => 'snow_',
            'zendesk' => 'zd_',
            'jira' => 'jira_',
            'freshservice' => 'fs_',
            default => 'sdp_',
        };
    }
}
