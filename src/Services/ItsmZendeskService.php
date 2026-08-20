<?php
/**
 * Zendesk Support Tickets API v2.
 * Auth: Basic {email}/token:{api_token}
 * POST/PUT/GET /api/v2/tickets.json
 */
declare(strict_types=1);

class ItsmZendeskService
{
    public static function isConfigured(): bool
    {
        $c = self::config();
        return $c['subdomain'] !== '' && $c['email'] !== '' && $c['token'] !== '';
    }

    /** @return array<string,mixed> */
    public static function config(): array
    {
        return [
            'subdomain' => self::normalizeSubdomain(ItsmService::settingGet('zd_subdomain', '')),
            'email' => trim(ItsmService::settingGet('zd_email', '')),
            'token' => ItsmService::settingSecret('zd_api_token'),
            'requester_email' => trim(ItsmService::settingGet('zd_requester_email', '')),
            'auto_create' => ItsmService::settingGet('zd_auto_create', '1') === '1',
            'auto_note' => ItsmService::settingGet('zd_auto_note', '1') === '1',
            'close_on_complete' => ItsmService::settingGet('zd_close_on_complete', '0') === '1',
            'closed_status' => trim(ItsmService::settingGet('zd_closed_status', 'solved')) ?: 'solved',
            'cancelled_status' => trim(ItsmService::settingGet('zd_cancelled_status', 'closed')) ?: 'closed',
            'progress_status' => trim(ItsmService::settingGet('zd_progress_status', 'open')) ?: 'open',
            'tls_verify' => ItsmService::settingGet('zd_tls_verify', '1') === '1',
        ];
    }

    /** @param array<string,mixed> $post */
    public static function saveFromPost(array $post): void
    {
        $prev = self::config();
        $email = trim((string)($post['zd_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Zendesk agent email is not valid.');
        }
        $req = trim((string)($post['zd_requester_email'] ?? ''));
        if ($req !== '' && !filter_var($req, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Zendesk requester email is not valid.');
        }
        ItsmService::settingSet('zd_subdomain', self::normalizeSubdomain((string)($post['zd_subdomain'] ?? '')));
        ItsmService::settingSet('zd_email', $email);
        ItsmService::settingSet(
            'zd_api_token',
            ItsmService::keepSecret((string)($post['zd_api_token'] ?? ''), (string)$prev['token']),
            true
        );
        ItsmService::settingSet('zd_requester_email', $req);
        ItsmService::settingSet('zd_auto_create', !empty($post['zd_auto_create']) ? '1' : '0');
        ItsmService::settingSet('zd_auto_note', !empty($post['zd_auto_note']) ? '1' : '0');
        ItsmService::settingSet('zd_close_on_complete', !empty($post['zd_close_on_complete']) ? '1' : '0');
        ItsmService::settingSet('zd_closed_status', trim((string)($post['zd_closed_status'] ?? 'solved')) ?: 'solved');
        ItsmService::settingSet('zd_cancelled_status', trim((string)($post['zd_cancelled_status'] ?? 'closed')) ?: 'closed');
        ItsmService::settingSet('zd_progress_status', trim((string)($post['zd_progress_status'] ?? 'open')) ?: 'open');
        ItsmService::settingSet('zd_tls_verify', !empty($post['zd_tls_verify']) ? '1' : '0');
        ItsmService::settingSet('zd_inbound_status', !empty($post['zd_inbound_status']) ? '1' : '0');
    }

    /**
     * @param array<string,mixed>|null $override
     * @return array{ok:bool,summary:string,steps:list<array{name:string,ok:bool,detail:string}>}
     */
    public static function testConnection(?array $override = null): array
    {
        $cfg = self::merge($override);
        if ($cfg['subdomain'] === '' || $cfg['email'] === '' || $cfg['token'] === '') {
            return ['ok' => false, 'summary' => 'Subdomain, agent email, and API token are required.', 'steps' => []];
        }
        $steps = [['name' => 'Host', 'ok' => true, 'detail' => 'https://' . $cfg['subdomain'] . '.zendesk.com']];
        try {
            $json = self::api('GET', '/api/v2/tickets.json?per_page=1', null, $cfg);
            $n = isset($json['tickets']) && is_array($json['tickets']) ? count($json['tickets']) : 0;
            $steps[] = ['name' => 'GET /api/v2/tickets.json', 'ok' => true, 'detail' => 'Tickets API OK (sample ' . $n . ').'];
        } catch (Throwable $e) {
            $steps[] = ['name' => 'Tickets API', 'ok' => false, 'detail' => $e->getMessage()];
            return ['ok' => false, 'summary' => 'Zendesk rejected the request.', 'steps' => $steps];
        }
        return ['ok' => true, 'summary' => 'Zendesk Tickets API works.', 'steps' => $steps];
    }

    /** @return array{id:string,display_id:string,url:string} */
    public static function createFromWorkOrder(int $workOrderId): array
    {
        $wo = ItsmService::loadWorkOrder($workOrderId);
        if (trim((string)($wo['itsm_request_id'] ?? '')) !== '') {
            return [
                'id' => (string)$wo['itsm_request_id'],
                'display_id' => (string)($wo['itsm_display_id'] ?? $wo['itsm_request_id']),
                'url' => (string)($wo['itsm_url'] ?? ''),
            ];
        }
        $cfg = self::config();
        $html = ItsmService::buildDescription($wo);
        $ticket = [
            'subject' => ItsmService::subjectFromTitle((string)$wo['title']),
            'comment' => [
                'html_body' => $html,
                'public' => false,
            ],
            'external_id' => ItsmService::woMarker($workOrderId),
            'tags' => ['coldaisle'],
            'type' => 'task',
        ];
        if ($cfg['requester_email'] !== '') {
            $ticket['requester'] = ['email' => $cfg['requester_email']];
        }
        $json = self::api('POST', '/api/v2/tickets.json', ['ticket' => $ticket], $cfg);
        $row = is_array($json['ticket'] ?? null) ? $json['ticket'] : [];
        $id = (string)($row['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Zendesk created a ticket but returned no id.');
        }
        $url = self::uiUrl($cfg, $id);
        ItsmService::storeLink($workOrderId, $wo, $id, $id, $url, 'zendesk');
        return ['id' => $id, 'display_id' => $id, 'url' => $url];
    }

    /** @return array{id:string,display_id:string,url:string} */
    public static function linkExisting(int $workOrderId, string $ticket): array
    {
        $ticket = trim($ticket);
        if (!preg_match('/^\d+$/', $ticket)) {
            throw new RuntimeException('Enter a numeric Zendesk ticket id.');
        }
        $wo = ItsmService::loadWorkOrder($workOrderId);
        $cfg = self::config();
        $json = self::api('GET', '/api/v2/tickets/' . rawurlencode($ticket) . '.json', null, $cfg);
        $row = is_array($json['ticket'] ?? null) ? $json['ticket'] : [];
        $id = (string)($row['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Zendesk ticket #' . $ticket . ' was not found.');
        }
        $url = self::uiUrl($cfg, $id);
        ItsmService::storeLink($workOrderId, $wo, $id, $id, $url, 'zendesk');
        return ['id' => $id, 'display_id' => $id, 'url' => $url];
    }

    /** @return array{ok:bool,action:string,work_order_id:?int,detail:string} */
    public static function pullFromWorkOrder(int $workOrderId): array
    {
        $wo = ItsmService::loadWorkOrder($workOrderId);
        $rid = trim((string)($wo['itsm_request_id'] ?? ''));
        if ($rid === '') {
            throw new RuntimeException('This work order is not linked to a Zendesk ticket.');
        }
        $cfg = self::config();
        $json = self::api('GET', '/api/v2/tickets/' . rawurlencode($rid) . '.json', null, $cfg);
        $row = is_array($json['ticket'] ?? null) ? $json['ticket'] : [];
        $id = (string)($row['id'] ?? $rid);
        return ItsmService::applyRemote($workOrderId, [
            'id' => $id,
            'display_id' => $id,
            'url' => self::uiUrl($cfg, $id),
            'subject' => (string)($row['subject'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
        ]);
    }

    public static function addNote(int $workOrderId, string $html): void
    {
        $wo = ItsmService::loadWorkOrder($workOrderId);
        $rid = trim((string)($wo['itsm_request_id'] ?? ''));
        if ($rid === '') {
            throw new RuntimeException('This work order is not linked to a Zendesk ticket.');
        }
        $cfg = self::config();
        self::api('PUT', '/api/v2/tickets/' . rawurlencode($rid) . '.json', [
            'ticket' => [
                'comment' => [
                    'html_body' => $html,
                    'public' => false,
                ],
            ],
        ], $cfg);
        ItsmService::touchSync($workOrderId, null);
    }

    public static function setRemoteStatus(int $workOrderId, string $woStatus): void
    {
        $wo = ItsmService::loadWorkOrder($workOrderId);
        $rid = trim((string)($wo['itsm_request_id'] ?? ''));
        if ($rid === '') {
            return;
        }
        $cfg = self::config();
        $st = match ($woStatus) {
            'completed' => (string)$cfg['closed_status'],
            'cancelled' => (string)$cfg['cancelled_status'],
            'in_progress' => (string)$cfg['progress_status'],
            default => '',
        };
        if ($st === '') {
            return;
        }
        self::api('PUT', '/api/v2/tickets/' . rawurlencode($rid) . '.json', [
            'ticket' => ['status' => $st],
        ], $cfg);
        ItsmService::touchSync($workOrderId, null);
    }

    /** @param array<string,mixed> $cfg */
    public static function uiUrl(array $cfg, string $id): string
    {
        return 'https://' . $cfg['subdomain'] . '.zendesk.com/agent/tickets/' . rawurlencode($id);
    }

    public static function normalizeSubdomain(string $raw): string
    {
        $s = strtolower(trim($raw));
        $s = preg_replace('#^https?://#', '', $s) ?? $s;
        $s = preg_replace('#\.zendesk\.com.*$#', '', $s) ?? $s;
        $s = trim($s, '/');
        return preg_replace('/[^a-z0-9-]/', '', $s) ?? $s;
    }

    /** @param array<string,mixed>|null $override */
    private static function merge(?array $override): array
    {
        $cfg = self::config();
        if (!is_array($override)) {
            return $cfg;
        }
        if (!empty($override['zd_subdomain'])) {
            $cfg['subdomain'] = self::normalizeSubdomain((string)$override['zd_subdomain']);
        }
        if (!empty($override['zd_email'])) {
            $cfg['email'] = trim((string)$override['zd_email']);
        }
        if (!empty($override['zd_api_token'])) {
            $cfg['token'] = (string)$override['zd_api_token'];
        }
        if (array_key_exists('zd_tls_verify', $override) || array_key_exists('tls_verify', $override)) {
            $cfg['tls_verify'] = !empty($override['zd_tls_verify'] ?? $override['tls_verify']);
        }
        return $cfg;
    }

    /**
     * @param array<string,mixed>|null $payload
     * @param array<string,mixed> $cfg
     * @return array<string,mixed>
     */
    private static function api(string $method, string $path, ?array $payload, array $cfg): array
    {
        $url = 'https://' . $cfg['subdomain'] . '.zendesk.com' . $path;
        $headers = [ItsmHttp::basicAuth((string)$cfg['email'] . '/token', (string)$cfg['token'])];
        return ItsmHttp::json($method, $url, $payload, $headers, !empty($cfg['tls_verify']), 'ColdAisle-Zendesk');
    }
}
