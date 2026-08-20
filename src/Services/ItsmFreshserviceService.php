<?php
/**
 * Freshservice Tickets API v2.
 * Auth: Basic api_key:X
 * POST /api/v2/tickets  PUT ticket  POST /tickets/{id}/notes
 */
declare(strict_types=1);

class ItsmFreshserviceService
{
    public static function isConfigured(): bool
    {
        $c = self::config();
        return $c['domain'] !== '' && $c['api_key'] !== '' && $c['email'] !== '';
    }

    /** @return array<string,mixed> */
    public static function config(): array
    {
        return [
            'domain' => self::normalizeDomain(ItsmService::settingGet('fs_domain', '')),
            'api_key' => ItsmService::settingSecret('fs_api_key'),
            'email' => trim(ItsmService::settingGet('fs_email', '')),
            'auto_create' => ItsmService::settingGet('fs_auto_create', '1') === '1',
            'auto_note' => ItsmService::settingGet('fs_auto_note', '1') === '1',
            'close_on_complete' => ItsmService::settingGet('fs_close_on_complete', '0') === '1',
            'closed_status' => trim(ItsmService::settingGet('fs_closed_status', '5')) ?: '5',
            'cancelled_status' => trim(ItsmService::settingGet('fs_cancelled_status', '5')) ?: '5',
            'progress_status' => trim(ItsmService::settingGet('fs_progress_status', '2')) ?: '2',
            'tls_verify' => ItsmService::settingGet('fs_tls_verify', '1') === '1',
        ];
    }

    /** @param array<string,mixed> $post */
    public static function saveFromPost(array $post): void
    {
        $prev = self::config();
        $email = trim((string)($post['fs_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Freshservice requester email is not valid (the API requires email to create a ticket).');
        }
        ItsmService::settingSet('fs_domain', self::normalizeDomain((string)($post['fs_domain'] ?? '')));
        ItsmService::settingSet(
            'fs_api_key',
            ItsmService::keepSecret((string)($post['fs_api_key'] ?? ''), (string)$prev['api_key']),
            true
        );
        ItsmService::settingSet('fs_email', $email);
        ItsmService::settingSet('fs_auto_create', !empty($post['fs_auto_create']) ? '1' : '0');
        ItsmService::settingSet('fs_auto_note', !empty($post['fs_auto_note']) ? '1' : '0');
        ItsmService::settingSet('fs_close_on_complete', !empty($post['fs_close_on_complete']) ? '1' : '0');
        ItsmService::settingSet('fs_closed_status', trim((string)($post['fs_closed_status'] ?? '5')) ?: '5');
        ItsmService::settingSet('fs_cancelled_status', trim((string)($post['fs_cancelled_status'] ?? '5')) ?: '5');
        ItsmService::settingSet('fs_progress_status', trim((string)($post['fs_progress_status'] ?? '2')) ?: '2');
        ItsmService::settingSet('fs_tls_verify', !empty($post['fs_tls_verify']) ? '1' : '0');
        ItsmService::settingSet('fs_inbound_status', !empty($post['fs_inbound_status']) ? '1' : '0');
    }

    /**
     * @param array<string,mixed>|null $override
     * @return array{ok:bool,summary:string,steps:list<array{name:string,ok:bool,detail:string}>}
     */
    public static function testConnection(?array $override = null): array
    {
        $cfg = self::merge($override);
        if ($cfg['domain'] === '' || $cfg['api_key'] === '') {
            return ['ok' => false, 'summary' => 'Domain and API key are required.', 'steps' => []];
        }
        $steps = [['name' => 'Host', 'ok' => true, 'detail' => 'https://' . $cfg['domain']]];
        try {
            $json = self::api('GET', '/api/v2/tickets?per_page=1', null, $cfg);
            $n = isset($json['tickets']) && is_array($json['tickets']) ? count($json['tickets']) : 0;
            $steps[] = ['name' => 'GET /api/v2/tickets', 'ok' => true, 'detail' => 'Tickets API OK (sample ' . $n . ').'];
        } catch (Throwable $e) {
            $steps[] = ['name' => 'Tickets API', 'ok' => false, 'detail' => $e->getMessage()];
            return ['ok' => false, 'summary' => 'Freshservice rejected the request.', 'steps' => $steps];
        }
        return ['ok' => true, 'summary' => 'Freshservice Tickets API works.', 'steps' => $steps];
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
        if ($cfg['email'] === '') {
            throw new RuntimeException('Freshservice requires a requester email (Settings → Ticketing).');
        }
        $html = ItsmService::buildDescription($wo);
        $payload = [
            'email' => $cfg['email'],
            'subject' => ItsmService::subjectFromTitle((string)$wo['title']),
            'description' => $html,
            'status' => 2,
            'priority' => 2,
            'source' => 2,
            'tags' => ['coldaisle'],
        ];
        $json = self::api('POST', '/api/v2/tickets', $payload, $cfg);
        $row = is_array($json['ticket'] ?? null) ? $json['ticket'] : [];
        $id = (string)($row['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Freshservice created a ticket but returned no id.');
        }
        $url = self::uiUrl($cfg, $id);
        ItsmService::storeLink($workOrderId, $wo, $id, $id, $url, 'freshservice');
        return ['id' => $id, 'display_id' => $id, 'url' => $url];
    }

    /** @return array{id:string,display_id:string,url:string} */
    public static function linkExisting(int $workOrderId, string $ticket): array
    {
        $ticket = trim($ticket);
        if (!preg_match('/^\d+$/', $ticket)) {
            throw new RuntimeException('Enter a numeric Freshservice ticket id.');
        }
        $wo = ItsmService::loadWorkOrder($workOrderId);
        $cfg = self::config();
        $json = self::api('GET', '/api/v2/tickets/' . rawurlencode($ticket), null, $cfg);
        $row = is_array($json['ticket'] ?? null) ? $json['ticket'] : [];
        $id = (string)($row['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Freshservice ticket #' . $ticket . ' was not found.');
        }
        $url = self::uiUrl($cfg, $id);
        ItsmService::storeLink($workOrderId, $wo, $id, $id, $url, 'freshservice');
        return ['id' => $id, 'display_id' => $id, 'url' => $url];
    }

    /** @return array{ok:bool,action:string,work_order_id:?int,detail:string} */
    public static function pullFromWorkOrder(int $workOrderId): array
    {
        $wo = ItsmService::loadWorkOrder($workOrderId);
        $rid = trim((string)($wo['itsm_request_id'] ?? ''));
        if ($rid === '') {
            throw new RuntimeException('This work order is not linked to a Freshservice ticket.');
        }
        $cfg = self::config();
        $json = self::api('GET', '/api/v2/tickets/' . rawurlencode($rid), null, $cfg);
        $row = is_array($json['ticket'] ?? null) ? $json['ticket'] : [];
        $id = (string)($row['id'] ?? $rid);
        $st = $row['status'] ?? '';
        return ItsmService::applyRemote($workOrderId, [
            'id' => $id,
            'display_id' => $id,
            'url' => self::uiUrl($cfg, $id),
            'subject' => (string)($row['subject'] ?? ''),
            'status' => (string)$st,
        ]);
    }

    public static function addNote(int $workOrderId, string $html): void
    {
        $wo = ItsmService::loadWorkOrder($workOrderId);
        $rid = trim((string)($wo['itsm_request_id'] ?? ''));
        if ($rid === '') {
            throw new RuntimeException('This work order is not linked to a Freshservice ticket.');
        }
        $cfg = self::config();
        self::api('POST', '/api/v2/tickets/' . rawurlencode($rid) . '/notes', [
            'body' => $html,
            'private' => true,
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
        self::api('PUT', '/api/v2/tickets/' . rawurlencode($rid), [
            'status' => (int)$st,
        ], $cfg);
        ItsmService::touchSync($workOrderId, null);
    }

    /** @param array<string,mixed> $cfg */
    public static function uiUrl(array $cfg, string $id): string
    {
        return 'https://' . $cfg['domain'] . '/a/tickets/' . rawurlencode($id);
    }

    public static function normalizeDomain(string $raw): string
    {
        $s = strtolower(trim($raw));
        $s = preg_replace('#^https?://#', '', $s) ?? $s;
        $s = trim(explode('/', $s)[0]);
        if ($s !== '' && !str_contains($s, '.')) {
            $s .= '.freshservice.com';
        }
        return $s;
    }

    /** @param array<string,mixed>|null $override */
    private static function merge(?array $override): array
    {
        $cfg = self::config();
        if (!is_array($override)) {
            return $cfg;
        }
        if (!empty($override['fs_domain'])) {
            $cfg['domain'] = self::normalizeDomain((string)$override['fs_domain']);
        }
        if (!empty($override['fs_api_key'])) {
            $cfg['api_key'] = (string)$override['fs_api_key'];
        }
        if (!empty($override['fs_email'])) {
            $cfg['email'] = trim((string)$override['fs_email']);
        }
        if (array_key_exists('fs_tls_verify', $override) || array_key_exists('tls_verify', $override)) {
            $cfg['tls_verify'] = !empty($override['fs_tls_verify'] ?? $override['tls_verify']);
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
        $url = 'https://' . $cfg['domain'] . $path;
        $headers = [ItsmHttp::basicAuth((string)$cfg['api_key'], 'X')];
        return ItsmHttp::json($method, $url, $payload, $headers, !empty($cfg['tls_verify']), 'ColdAisle-Freshservice');
    }
}
