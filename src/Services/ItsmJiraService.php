<?php
/**
 * Jira Cloud / Data Center REST API v2 (plain-text description, not ADF).
 * Auth: Basic email:api_token (Cloud) or username:password/PAT (DC).
 * POST /rest/api/2/issue  GET/PUT issue  POST comment  GET/POST transitions
 */
declare(strict_types=1);

class ItsmJiraService
{
    public static function isConfigured(): bool
    {
        $c = self::config();
        return $c['base'] !== '' && $c['email'] !== '' && $c['token'] !== '' && $c['project'] !== '';
    }

    /** @return array<string,mixed> */
    public static function config(): array
    {
        $base = rtrim(trim(ItsmService::settingGet('jira_base', '')), '/');
        return [
            'base' => $base,
            'email' => trim(ItsmService::settingGet('jira_email', '')),
            'token' => ItsmService::settingSecret('jira_token'),
            'project' => strtoupper(trim(ItsmService::settingGet('jira_project', ''))),
            'issue_type' => trim(ItsmService::settingGet('jira_issue_type', 'Task')) ?: 'Task',
            'auto_create' => ItsmService::settingGet('jira_auto_create', '1') === '1',
            'auto_note' => ItsmService::settingGet('jira_auto_note', '1') === '1',
            'close_on_complete' => ItsmService::settingGet('jira_close_on_complete', '0') === '1',
            'close_transition' => trim(ItsmService::settingGet('jira_close_transition', 'Done')) ?: 'Done',
            'cancel_transition' => trim(ItsmService::settingGet('jira_cancel_transition', 'Cancelled')),
            'progress_transition' => trim(ItsmService::settingGet('jira_progress_transition', 'In Progress')),
            'tls_verify' => ItsmService::settingGet('jira_tls_verify', '1') === '1',
        ];
    }

    /** @param array<string,mixed> $post */
    public static function saveFromPost(array $post): void
    {
        $prev = self::config();
        $base = trim((string)($post['jira_base'] ?? ''));
        if ($base !== '') {
            $base = ItsmHttp::httpsOrigin($base);
        }
        $email = trim((string)($post['jira_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL) && !str_contains($email, '\\')) {
            // Cloud uses email; Data Center may use a username
        }
        ItsmService::settingSet('jira_base', $base);
        ItsmService::settingSet('jira_email', $email);
        ItsmService::settingSet(
            'jira_token',
            ItsmService::keepSecret((string)($post['jira_token'] ?? ''), (string)$prev['token']),
            true
        );
        ItsmService::settingSet('jira_project', strtoupper(trim((string)($post['jira_project'] ?? ''))));
        ItsmService::settingSet('jira_issue_type', trim((string)($post['jira_issue_type'] ?? 'Task')) ?: 'Task');
        ItsmService::settingSet('jira_auto_create', !empty($post['jira_auto_create']) ? '1' : '0');
        ItsmService::settingSet('jira_auto_note', !empty($post['jira_auto_note']) ? '1' : '0');
        ItsmService::settingSet('jira_close_on_complete', !empty($post['jira_close_on_complete']) ? '1' : '0');
        ItsmService::settingSet('jira_close_transition', trim((string)($post['jira_close_transition'] ?? 'Done')) ?: 'Done');
        ItsmService::settingSet('jira_cancel_transition', trim((string)($post['jira_cancel_transition'] ?? 'Cancelled')));
        ItsmService::settingSet('jira_progress_transition', trim((string)($post['jira_progress_transition'] ?? 'In Progress')));
        ItsmService::settingSet('jira_tls_verify', !empty($post['jira_tls_verify']) ? '1' : '0');
        ItsmService::settingSet('jira_inbound_status', !empty($post['jira_inbound_status']) ? '1' : '0');
    }

    /**
     * @param array<string,mixed>|null $override
     * @return array{ok:bool,summary:string,steps:list<array{name:string,ok:bool,detail:string}>}
     */
    public static function testConnection(?array $override = null): array
    {
        $cfg = self::merge($override);
        if ($cfg['base'] === '' || $cfg['email'] === '' || $cfg['token'] === '') {
            return ['ok' => false, 'summary' => 'Base URL, email/username, and API token are required.', 'steps' => []];
        }
        $steps = [['name' => 'Site', 'ok' => true, 'detail' => $cfg['base']]];
        try {
            $me = self::api('GET', '/rest/api/2/myself', null, $cfg);
            $who = (string)($me['displayName'] ?? $me['name'] ?? $me['emailAddress'] ?? 'ok');
            $steps[] = ['name' => 'GET /rest/api/2/myself', 'ok' => true, 'detail' => 'Authenticated as ' . $who];
        } catch (Throwable $e) {
            $steps[] = ['name' => 'myself', 'ok' => false, 'detail' => $e->getMessage()];
            return ['ok' => false, 'summary' => 'Jira rejected the credentials.', 'steps' => $steps];
        }
        if ($cfg['project'] !== '') {
            try {
                $p = self::api('GET', '/rest/api/2/project/' . rawurlencode($cfg['project']), null, $cfg);
                $steps[] = [
                    'name' => 'Project ' . $cfg['project'],
                    'ok' => true,
                    'detail' => (string)($p['name'] ?? $cfg['project']) . ' · issue type ' . $cfg['issue_type'],
                ];
            } catch (Throwable $e) {
                $steps[] = ['name' => 'Project', 'ok' => false, 'detail' => $e->getMessage()];
                return ['ok' => false, 'summary' => 'Authenticated, but the project could not be read.', 'steps' => $steps];
            }
        }
        return ['ok' => true, 'summary' => 'Jira REST API v2 works.', 'steps' => $steps];
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
        $payload = [
            'fields' => [
                'project' => ['key' => $cfg['project']],
                'summary' => ItsmService::subjectFromTitle((string)$wo['title']),
                'description' => ItsmService::htmlToText($html),
                'issuetype' => ['name' => $cfg['issue_type']],
                'labels' => ['coldaisle'],
            ],
        ];
        $json = self::api('POST', '/rest/api/2/issue', $payload, $cfg);
        $key = (string)($json['key'] ?? '');
        $id = (string)($json['id'] ?? $key);
        if ($key === '') {
            throw new RuntimeException('Jira created an issue but returned no key.');
        }
        $url = self::uiUrl($cfg, $key);
        ItsmService::storeLink($workOrderId, $wo, $key, $key, $url, 'jira');
        return ['id' => $key, 'display_id' => $key, 'url' => $url];
    }

    /** @return array{id:string,display_id:string,url:string} */
    public static function linkExisting(int $workOrderId, string $ticket): array
    {
        $ticket = strtoupper(trim($ticket));
        if ($ticket === '') {
            throw new RuntimeException('Enter a Jira issue key (for example PROJ-123).');
        }
        $wo = ItsmService::loadWorkOrder($workOrderId);
        $cfg = self::config();
        $json = self::api('GET', '/rest/api/2/issue/' . rawurlencode($ticket) . '?fields=summary,status,issuetype', null, $cfg);
        $key = (string)($json['key'] ?? '');
        if ($key === '') {
            throw new RuntimeException('Jira issue ' . $ticket . ' was not found.');
        }
        $url = self::uiUrl($cfg, $key);
        ItsmService::storeLink($workOrderId, $wo, $key, $key, $url, 'jira');
        return ['id' => $key, 'display_id' => $key, 'url' => $url];
    }

    /** @return array{ok:bool,action:string,work_order_id:?int,detail:string} */
    public static function pullFromWorkOrder(int $workOrderId): array
    {
        $wo = ItsmService::loadWorkOrder($workOrderId);
        $rid = trim((string)($wo['itsm_request_id'] ?? $wo['itsm_display_id'] ?? ''));
        if ($rid === '') {
            throw new RuntimeException('This work order is not linked to a Jira issue.');
        }
        $cfg = self::config();
        $json = self::api('GET', '/rest/api/2/issue/' . rawurlencode($rid) . '?fields=summary,status', null, $cfg);
        $key = (string)($json['key'] ?? $rid);
        $fields = is_array($json['fields'] ?? null) ? $json['fields'] : [];
        $st = $fields['status']['name'] ?? $fields['status']['statusCategory']['name'] ?? '';
        return ItsmService::applyRemote($workOrderId, [
            'id' => $key,
            'display_id' => $key,
            'url' => self::uiUrl($cfg, $key),
            'subject' => (string)($fields['summary'] ?? ''),
            'status' => (string)$st,
        ]);
    }

    public static function addNote(int $workOrderId, string $html): void
    {
        $wo = ItsmService::loadWorkOrder($workOrderId);
        $rid = trim((string)($wo['itsm_request_id'] ?? ''));
        if ($rid === '') {
            throw new RuntimeException('This work order is not linked to a Jira issue.');
        }
        $cfg = self::config();
        self::api('POST', '/rest/api/2/issue/' . rawurlencode($rid) . '/comment', [
            'body' => ItsmService::htmlToText($html),
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
        $want = match ($woStatus) {
            'completed' => (string)$cfg['close_transition'],
            'cancelled' => (string)$cfg['cancel_transition'],
            'in_progress' => (string)$cfg['progress_transition'],
            default => '',
        };
        if ($want === '') {
            return;
        }
        $json = self::api('GET', '/rest/api/2/issue/' . rawurlencode($rid) . '/transitions', null, $cfg);
        $list = is_array($json['transitions'] ?? null) ? $json['transitions'] : [];
        $tid = '';
        $wantLc = strtolower($want);
        foreach ($list as $tr) {
            if (!is_array($tr)) {
                continue;
            }
            $name = strtolower((string)($tr['name'] ?? ''));
            $to = strtolower((string)($tr['to']['name'] ?? ''));
            if ($name === $wantLc || $to === $wantLc || str_contains($name, $wantLc) || str_contains($to, $wantLc)) {
                $tid = (string)($tr['id'] ?? '');
                break;
            }
        }
        if ($tid === '') {
            throw new RuntimeException(
                'No Jira transition named "' . $want . '" is available on this issue. '
                . 'Check Settings → Ticketing (workflow names vary by project).'
            );
        }
        self::api('POST', '/rest/api/2/issue/' . rawurlencode($rid) . '/transitions', [
            'transition' => ['id' => $tid],
        ], $cfg);
        ItsmService::touchSync($workOrderId, null);
    }

    /** @param array<string,mixed> $cfg */
    public static function uiUrl(array $cfg, string $key): string
    {
        return rtrim((string)$cfg['base'], '/') . '/browse/' . rawurlencode($key);
    }

    /** @param array<string,mixed>|null $override */
    private static function merge(?array $override): array
    {
        $cfg = self::config();
        if (!is_array($override)) {
            return $cfg;
        }
        if (!empty($override['jira_base'])) {
            $cfg['base'] = rtrim((string)$override['jira_base'], '/');
        }
        if (!empty($override['jira_email'])) {
            $cfg['email'] = trim((string)$override['jira_email']);
        }
        if (!empty($override['jira_token'])) {
            $cfg['token'] = (string)$override['jira_token'];
        }
        if (!empty($override['jira_project'])) {
            $cfg['project'] = strtoupper(trim((string)$override['jira_project']));
        }
        if (!empty($override['jira_issue_type'])) {
            $cfg['issue_type'] = trim((string)$override['jira_issue_type']);
        }
        if (array_key_exists('jira_tls_verify', $override) || array_key_exists('tls_verify', $override)) {
            $cfg['tls_verify'] = !empty($override['jira_tls_verify'] ?? $override['tls_verify']);
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
        $url = rtrim((string)$cfg['base'], '/') . $path;
        $headers = [ItsmHttp::basicAuth((string)$cfg['email'], (string)$cfg['token'])];
        return ItsmHttp::json($method, $url, $payload, $headers, !empty($cfg['tls_verify']), 'ColdAisle-Jira');
    }
}
