<?php
/**
 * ServiceNow Table API (incident or change_request).
 * Docs: /api/now/table/{table} — Basic auth, JSON.
 * Outbound only.
 */
declare(strict_types=1);

class ItsmSnowService
{
    public static function isConfigured(): bool
    {
        $c = self::config();
        return $c['instance'] !== '' && $c['username'] !== '' && $c['password'] !== '';
    }

    /** @return array<string,mixed> */
    public static function config(): array
    {
        $table = strtolower(trim(ItsmService::settingGet('snow_table', 'incident')));
        if (!in_array($table, ['incident', 'change_request'], true)) {
            $table = 'incident';
        }
        return [
            'instance' => rtrim(trim(ItsmService::settingGet('snow_instance', '')), '/'),
            'username' => trim(ItsmService::settingGet('snow_username', '')),
            'password' => ItsmService::settingSecret('snow_password'),
            'table' => $table,
            'caller' => trim(ItsmService::settingGet('snow_caller', '')),
            'auto_create' => ItsmService::settingGet('snow_auto_create', '1') === '1',
            'auto_note' => ItsmService::settingGet('snow_auto_note', '1') === '1',
            'close_on_complete' => ItsmService::settingGet('snow_close_on_complete', '0') === '1',
            'closed_state' => trim(ItsmService::settingGet('snow_closed_state', $table === 'incident' ? '7' : '3')),
            'cancelled_state' => trim(ItsmService::settingGet('snow_cancelled_state', $table === 'incident' ? '8' : '4')),
            'progress_state' => trim(ItsmService::settingGet('snow_progress_state', $table === 'incident' ? '2' : '-1')),
            'tls_verify' => ItsmService::settingGet('snow_tls_verify', '1') === '1',
        ];
    }

    /** @param array<string,mixed> $post */
    public static function saveFromPost(array $post): void
    {
        $prev = self::config();
        $inst = trim((string)($post['snow_instance'] ?? ''));
        if ($inst !== '') {
            $inst = ItsmHttp::httpsOrigin($inst);
        }
        $table = strtolower(trim((string)($post['snow_table'] ?? 'incident')));
        if (!in_array($table, ['incident', 'change_request'], true)) {
            $table = 'incident';
        }
        ItsmService::settingSet('snow_instance', $inst);
        ItsmService::settingSet('snow_username', trim((string)($post['snow_username'] ?? '')));
        ItsmService::settingSet(
            'snow_password',
            ItsmService::keepSecret((string)($post['snow_password'] ?? ''), (string)$prev['password']),
            true
        );
        ItsmService::settingSet('snow_table', $table);
        ItsmService::settingSet('snow_caller', trim((string)($post['snow_caller'] ?? '')));
        ItsmService::settingSet('snow_auto_create', !empty($post['snow_auto_create']) ? '1' : '0');
        ItsmService::settingSet('snow_auto_note', !empty($post['snow_auto_note']) ? '1' : '0');
        ItsmService::settingSet('snow_close_on_complete', !empty($post['snow_close_on_complete']) ? '1' : '0');
        ItsmService::settingSet('snow_closed_state', trim((string)($post['snow_closed_state'] ?? '')) ?: ($table === 'incident' ? '7' : '3'));
        ItsmService::settingSet('snow_cancelled_state', trim((string)($post['snow_cancelled_state'] ?? '')) ?: ($table === 'incident' ? '8' : '4'));
        ItsmService::settingSet('snow_progress_state', trim((string)($post['snow_progress_state'] ?? '')) ?: ($table === 'incident' ? '2' : '-1'));
        ItsmService::settingSet('snow_tls_verify', !empty($post['snow_tls_verify']) ? '1' : '0');
        ItsmService::settingSet('snow_inbound_status', !empty($post['snow_inbound_status']) ? '1' : '0');
    }

    /**
     * @param array<string,mixed>|null $override
     * @return array{ok:bool,summary:string,steps:list<array{name:string,ok:bool,detail:string}>}
     */
    public static function testConnection(?array $override = null): array
    {
        $cfg = self::merge($override);
        $steps = [];
        if ($cfg['instance'] === '' || $cfg['username'] === '' || $cfg['password'] === '') {
            return ['ok' => false, 'summary' => 'Instance, username, and password (or API token) are required.', 'steps' => []];
        }
        $steps[] = ['name' => 'Instance', 'ok' => true, 'detail' => $cfg['instance'] . ' · table ' . $cfg['table']];
        try {
            $json = self::api('GET', '/api/now/table/' . rawurlencode($cfg['table']) . '?sysparm_limit=1', null, $cfg);
            $n = isset($json['result']) && is_array($json['result']) ? count($json['result']) : 0;
            $steps[] = ['name' => 'GET /api/now/table/' . $cfg['table'], 'ok' => true, 'detail' => 'Table API OK (sample rows ' . $n . ').'];
        } catch (Throwable $e) {
            $steps[] = ['name' => 'Table API', 'ok' => false, 'detail' => $e->getMessage()];
            return ['ok' => false, 'summary' => 'ServiceNow rejected the request.', 'steps' => $steps];
        }
        return ['ok' => true, 'summary' => 'ServiceNow Table API works.', 'steps' => $steps];
    }

    /** @return array{id:string,display_id:string,url:string} */
    public static function createFromWorkOrder(int $workOrderId): array
    {
        $wo = ItsmService::loadWorkOrder($workOrderId);
        if (trim((string)($wo['itsm_request_id'] ?? '')) !== '') {
            return [
                'id' => (string)$wo['itsm_request_id'],
                'display_id' => (string)($wo['itsm_display_id'] ?? ''),
                'url' => (string)($wo['itsm_url'] ?? ''),
            ];
        }
        $cfg = self::config();
        $html = ItsmService::buildDescription($wo);
        $body = [
            'short_description' => ItsmService::subjectFromTitle((string)$wo['title']),
            'description' => ItsmService::htmlToText($html),
            'work_notes' => ItsmService::htmlToText($html),
        ];
        if ($cfg['caller'] !== '') {
            if (str_contains($cfg['caller'], '@')) {
                $body['caller_id'] = $cfg['caller'];
            } else {
                $body['caller_id'] = $cfg['caller'];
            }
        }
        $json = self::api('POST', '/api/now/table/' . rawurlencode($cfg['table']), $body, $cfg);
        $row = is_array($json['result'] ?? null) ? $json['result'] : [];
        $sysId = (string)($row['sys_id'] ?? '');
        $number = (string)($row['number'] ?? '');
        if ($sysId === '') {
            throw new RuntimeException('ServiceNow created a record but returned no sys_id.');
        }
        $url = self::uiUrl($cfg, $sysId);
        ItsmService::storeLink($workOrderId, $wo, $sysId, $number, $url, 'servicenow');
        return ['id' => $sysId, 'display_id' => $number, 'url' => $url];
    }

    /** @return array{id:string,display_id:string,url:string} */
    public static function linkExisting(int $workOrderId, string $ticket): array
    {
        $ticket = trim($ticket);
        if ($ticket === '') {
            throw new RuntimeException('Enter a ServiceNow number (INC0010001) or sys_id.');
        }
        $wo = ItsmService::loadWorkOrder($workOrderId);
        $cfg = self::config();
        $row = null;
        if (preg_match('/^[a-f0-9]{32}$/i', $ticket)) {
            $json = self::api('GET', '/api/now/table/' . rawurlencode($cfg['table']) . '/' . rawurlencode($ticket), null, $cfg);
            $row = is_array($json['result'] ?? null) ? $json['result'] : null;
        }
        if ($row === null) {
            $q = 'number=' . str_replace(['^', ' '], '', $ticket);
            $json = self::api(
                'GET',
                '/api/now/table/' . rawurlencode($cfg['table']) . '?sysparm_query=' . rawurlencode($q) . '&sysparm_limit=1',
                null,
                $cfg
            );
            $list = is_array($json['result'] ?? null) ? $json['result'] : [];
            $row = is_array($list[0] ?? null) ? $list[0] : null;
        }
        if (!is_array($row) || empty($row['sys_id'])) {
            throw new RuntimeException('No ServiceNow ' . $cfg['table'] . ' matched "' . $ticket . '".');
        }
        $sysId = (string)$row['sys_id'];
        $number = (string)($row['number'] ?? $ticket);
        $url = self::uiUrl($cfg, $sysId);
        ItsmService::storeLink($workOrderId, $wo, $sysId, $number, $url, 'servicenow');
        return ['id' => $sysId, 'display_id' => $number, 'url' => $url];
    }

    /** @return array{ok:bool,action:string,work_order_id:?int,detail:string} */
    public static function pullFromWorkOrder(int $workOrderId): array
    {
        $wo = ItsmService::loadWorkOrder($workOrderId);
        $rid = trim((string)($wo['itsm_request_id'] ?? ''));
        if ($rid === '') {
            throw new RuntimeException('This work order is not linked to a ServiceNow record.');
        }
        $cfg = self::config();
        $json = self::api('GET', '/api/now/table/' . rawurlencode($cfg['table']) . '/' . rawurlencode($rid), null, $cfg);
        $row = is_array($json['result'] ?? null) ? $json['result'] : [];
        $state = $row['state'] ?? '';
        if (is_array($state)) {
            $state = $state['value'] ?? $state['display_value'] ?? '';
        }
        return ItsmService::applyRemote($workOrderId, [
            'id' => (string)($row['sys_id'] ?? $rid),
            'display_id' => (string)($row['number'] ?? ''),
            'url' => self::uiUrl($cfg, (string)($row['sys_id'] ?? $rid)),
            'subject' => (string)($row['short_description'] ?? ''),
            'status' => (string)$state,
        ]);
    }

    public static function addNote(int $workOrderId, string $html): void
    {
        $wo = ItsmService::loadWorkOrder($workOrderId);
        $rid = trim((string)($wo['itsm_request_id'] ?? ''));
        if ($rid === '') {
            throw new RuntimeException('This work order is not linked to a ServiceNow record.');
        }
        $cfg = self::config();
        self::api('PATCH', '/api/now/table/' . rawurlencode($cfg['table']) . '/' . rawurlencode($rid), [
            'work_notes' => ItsmService::htmlToText($html),
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
        $state = match ($woStatus) {
            'completed' => (string)$cfg['closed_state'],
            'cancelled' => (string)$cfg['cancelled_state'],
            'in_progress' => (string)$cfg['progress_state'],
            default => '',
        };
        if ($state === '') {
            return;
        }
        self::api('PATCH', '/api/now/table/' . rawurlencode($cfg['table']) . '/' . rawurlencode($rid), [
            'state' => $state,
        ], $cfg);
        ItsmService::touchSync($workOrderId, null);
    }

    /** @param array<string,mixed> $cfg */
    public static function uiUrl(array $cfg, string $sysId): string
    {
        $table = (string)$cfg['table'];
        return rtrim((string)$cfg['instance'], '/') . '/nav_to.do?uri=' . rawurlencode($table . '.do?sys_id=' . $sysId);
    }

    /** @param array<string,mixed>|null $override */
    private static function merge(?array $override): array
    {
        $cfg = self::config();
        if (!is_array($override)) {
            return $cfg;
        }
        foreach (['instance', 'username', 'table', 'caller'] as $k) {
            if (!empty($override['snow_' . $k]) && is_string($override['snow_' . $k])) {
                $cfg[$k] = $k === 'instance'
                    ? rtrim($override['snow_' . $k], '/')
                    : trim($override['snow_' . $k]);
            } elseif (!empty($override[$k]) && is_string($override[$k])) {
                $cfg[$k] = trim($override[$k]);
            }
        }
        if (!empty($override['snow_password'])) {
            $cfg['password'] = (string)$override['snow_password'];
        }
        if (array_key_exists('snow_tls_verify', $override) || array_key_exists('tls_verify', $override)) {
            $cfg['tls_verify'] = !empty($override['snow_tls_verify'] ?? $override['tls_verify']);
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
        $url = rtrim((string)$cfg['instance'], '/') . $path;
        $headers = [
            ItsmHttp::basicAuth((string)$cfg['username'], (string)$cfg['password']),
            'Accept: application/json',
        ];
        return ItsmHttp::json($method, $url, $payload, $headers, !empty($cfg['tls_verify']), 'ColdAisle-ServiceNow');
    }
}
