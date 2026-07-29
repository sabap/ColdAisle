<?php
/**
 * OpenDCIM import API (Settings wizard).
 *
 * POST actions: test | preview | import | status
 * GET: ?job_id= for status poll
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/src/Services/OpenDcimClient.php';
require_once dirname(__DIR__) . '/src/Services/OpenDcimImportService.php';
require_once dirname(__DIR__) . '/src/Services/OpenDcimImportJob.php';
require_once dirname(__DIR__) . '/includes/power_helpers.php';

try {
    opendcim_api_main();
} catch (Throwable $e) {
    App::log('opendcim_import API: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), 'error');
    App::json([
        'ok' => false,
        'error' => 'Import API error: ' . $e->getMessage(),
    ], 500);
}

function opendcim_api_main(): void
{
    $user = AuthManager::user();
    if (!$user || !AuthManager::can($user, 'manage_settings')) {
        App::json(['error' => 'Forbidden'], 403);
    }

    $method = api_method();
    if ($method === 'GET') {
        $jobId = trim((string)($_GET['job_id'] ?? ''));
        if ($jobId === '') {
            App::json(['error' => 'job_id required'], 400);
        }
        $job = OpenDcimImportJob::read($jobId);
        if (!$job) {
            App::json(['error' => 'Job not found'], 404);
        }
        unset($job['connection']);
        App::json(['job' => $job]);
    }

    if ($method !== 'POST') {
        App::json(['error' => 'Method not allowed'], 405);
    }

    api_require_csrf();
    $d = api_read_json();
    $action = strtolower(trim((string)($d['action'] ?? '')));

    if ($action === 'status') {
        $jobId = trim((string)($d['job_id'] ?? ''));
        $job = OpenDcimImportJob::read($jobId);
        if (!$job) {
            App::json(['error' => 'Job not found'], 404);
        }
        unset($job['connection']);
        App::json(['job' => $job]);
    }

    if (!in_array($action, ['test', 'preview', 'import'], true)) {
        App::json(['error' => 'action must be test, preview, import, or status'], 400);
    }

    try {
        $conn = buildConnectionFromRequest($d);
    } catch (Throwable $e) {
        App::json(['error' => $e->getMessage()], 400);
    }

    $opts = [
        'mode' => OpenDcimImportService::MODE_MERGE,
        'include_disposed' => !empty($d['include_disposed']),
        'include_ports' => !array_key_exists('include_ports', $d) || !empty($d['include_ports']),
        'include_power' => !array_key_exists('include_power', $d) || !empty($d['include_power']),
        'include_audits' => !array_key_exists('include_audits', $d) || !empty($d['include_audits']),
        'include_images' => !array_key_exists('include_images', $d) || !empty($d['include_images']),
        'target_datacenter_id' => !empty($d['target_datacenter_id']) ? (int)$d['target_datacenter_id'] : null,
    ];
    if (!empty($d['datacenter_ids']) && is_array($d['datacenter_ids'])) {
        $opts['datacenter_ids'] = array_map('strval', $d['datacenter_ids']);
    } elseif (!empty($d['opendcim_dc_id'])) {
        $opts['datacenter_ids'] = [(string)$d['opendcim_dc_id']];
    }

    // Fast path: test inline
    if ($action === 'test') {
        try {
            $client = new OpenDcimClient($conn);
            $result = $client->testConnection();
            AuditService::log((int)$user['user_id'], $user['username'], 'opendcim_test', 'system', null, [
                'ok' => !empty($result['ok']),
                'base' => $result['base_url'] ?? '',
            ]);
            App::json(['ok' => !empty($result['ok']), 'result' => $result]);
        } catch (Throwable $e) {
            App::json(['ok' => false, 'error' => $e->getMessage(), 'result' => null], 400);
        }
    }

    // preview / import as background jobs
    $jobId = OpenDcimImportJob::create([
        'action' => $action,
        'state' => 'queued',
        'message' => $action === 'preview' ? 'Preview queued' : 'Import queued',
        'connection' => $conn,
        'options' => array_merge($opts, [
            'dry_run' => $action === 'preview',
        ]),
        'requested_by' => $user['username'] ?? '',
    ]);

    $spawned = OpenDcimImportJob::spawnWorker($jobId);

    // Give worker a short moment to flip to running
    usleep(400000);
    $job = OpenDcimImportJob::read($jobId);

    // If still queued, worker likely failed to start — run inline with raised limits
    if ($job && ($job['state'] ?? '') === 'queued') {
        OpenDcimImportJob::patch($jobId, [
            'log_append' => $spawned
                ? 'Worker still queued — running inline in web request (may take several minutes)…'
                : 'Worker spawn unavailable — running inline…',
            'message' => 'Running…',
            'state' => 'running',
            'percent' => 5,
        ]);
        try {
            @set_time_limit(0);
            @ini_set('memory_limit', '512M');
            ignore_user_abort(true);
            runJobInline($jobId, $action, $conn, $opts);
        } catch (Throwable $e) {
            App::log('opendcim inline job: ' . $e->getMessage(), 'error');
            OpenDcimImportJob::patch($jobId, [
                'state' => 'error',
                'error' => $e->getMessage(),
                'message' => 'Failed: ' . $e->getMessage(),
                'percent' => 100,
                'log_append' => 'ERROR: ' . $e->getMessage(),
            ]);
        }
    }

    try {
        AuditService::log((int)$user['user_id'], $user['username'], 'opendcim_' . $action, 'system', null, [
            'job_id' => $jobId,
        ]);
    } catch (Throwable $e) {
        // non-fatal
    }

    $job = OpenDcimImportJob::read($jobId);
    if (is_array($job)) {
        unset($job['connection']);
    }
    App::json([
        'ok' => true,
        'job_id' => $jobId,
        'job' => $job,
    ]);
}

/**
 * @param array<string,mixed> $d
 * @return array<string,mixed>
 */
function buildConnectionFromRequest(array $d): array
{
    $source = strtolower(trim((string)($d['source'] ?? 'live')));
    if ($source === 'offline' || $source === 'cache' || !empty($d['cache_dir'])) {
        $dir = trim((string)($d['cache_dir'] ?? ''));
        if ($dir === '') {
            $dir = App::ROOT . '/storage/tmp/opendcim_probe';
        }
        if (!is_dir($dir)) {
            throw new InvalidArgumentException('Offline cache directory not found: ' . $dir);
        }
        return ['cache_dir' => $dir];
    }

    $url = trim((string)($d['base_url'] ?? $d['url'] ?? ''));
    $user = trim((string)($d['user_id'] ?? $d['username'] ?? ''));
    $key = (string)($d['api_key'] ?? $d['key'] ?? '');
    if ($url === '' || $user === '' || $key === '') {
        $cfg = App::config()['opendcim'] ?? [];
        if ($url === '') {
            $url = (string)($cfg['base_url'] ?? '');
        }
        if ($user === '') {
            $user = (string)($cfg['user_id'] ?? '');
        }
        if ($key === '') {
            $key = (string)($cfg['api_key'] ?? '');
        }
    }
    if ($url === '' || $user === '' || $key === '') {
        throw new InvalidArgumentException('OpenDCIM URL, UserID, and API key are required (or use offline cache).');
    }
    $resolve = [];
    $resolveHost = trim((string)($d['resolve_host'] ?? ''));
    $resolveIp = trim((string)($d['resolve_ip'] ?? ''));
    if ($resolveHost !== '' && $resolveIp !== '') {
        $resolve[$resolveHost] = $resolveIp;
    }
    $resolveStr = trim((string)($d['resolve'] ?? ''));
    if ($resolveStr !== '' && preg_match('/^(.+):(\d{1,3}(?:\.\d{1,3}){3})$/', $resolveStr, $m)) {
        $resolve[$m[1]] = $m[2];
    }
    return [
        'base_url' => $url,
        'user_id' => $user,
        'api_key' => $key,
        'tls_verify' => empty($d['insecure']) && empty($d['tls_insecure']),
        'timeout' => max(30, min(300, (int)($d['timeout'] ?? 120))),
        'resolve' => $resolve,
    ];
}

/**
 * @param array<string,mixed> $conn
 * @param array<string,mixed> $opts
 */
function runJobInline(string $jobId, string $action, array $conn, array $opts): void
{
    OpenDcimImportJob::patch($jobId, [
        'state' => 'running',
        'message' => 'Running…',
        'percent' => 10,
        'log_append' => 'Inline worker started',
    ]);

    $client = new OpenDcimClient($conn);
    $importOpts = array_merge($opts, [
        'dry_run' => $action === 'preview',
        'progress' => static function (string $msg) use ($jobId): void {
            OpenDcimImportJob::patch($jobId, [
                'log_append' => $msg,
                'message' => $msg,
            ]);
        },
    ]);
    $svc = new OpenDcimImportService($client, $importOpts);

    if ($action === 'preview') {
        $result = $svc->runModeA();
        $plan = $svc->planModeA();
        $summary = $svc->fetchSummary();
        OpenDcimImportJob::patch($jobId, [
            'state' => 'done',
            'percent' => 100,
            'message' => 'Preview complete (no changes written)',
            'stats' => $result['stats'] ?? [],
            'result' => [
                'type' => 'preview',
                'dry_run' => true,
                'stats' => $result['stats'] ?? [],
                'errors' => $result['errors'] ?? [],
                'plan' => $plan,
                'summary' => $summary,
            ],
        ]);
        return;
    }

    $result = $svc->runModeA();
    OpenDcimImportJob::patch($jobId, [
        'state' => !empty($result['ok']) ? 'done' : 'error',
        'percent' => 100,
        'message' => !empty($result['ok']) ? 'Migration complete' : 'Migration finished with errors',
        'stats' => $result['stats'] ?? [],
        'error' => !empty($result['errors']) ? implode('; ', $result['errors']) : null,
        'result' => [
            'type' => 'import',
            'dry_run' => false,
            'stats' => $result['stats'] ?? [],
            'errors' => $result['errors'] ?? [],
        ],
    ]);
}
