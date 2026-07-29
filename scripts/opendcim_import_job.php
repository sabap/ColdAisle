<?php
/**
 * Background worker for OpenDCIM import jobs (started by API).
 * Usage: php scripts/opendcim_import_job.php <job_id>
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/src/App.php';
require_once $root . '/src/Services/OpenDcimClient.php';
require_once $root . '/src/Services/OpenDcimImportService.php';
require_once $root . '/src/Services/OpenDcimImportJob.php';
require_once $root . '/includes/power_helpers.php';

$jobId = $argv[1] ?? '';
if ($jobId === '') {
    fwrite(STDERR, "job_id required\n");
    exit(2);
}

try {
    App::boot();
} catch (Throwable $e) {
    OpenDcimImportJob::patch($jobId, [
        'state' => 'error',
        'error' => 'App boot failed: ' . $e->getMessage(),
        'message' => 'Failed to start',
        'percent' => 0,
    ]);
    exit(1);
}

$job = OpenDcimImportJob::read($jobId);
if (!$job) {
    fwrite(STDERR, "job not found\n");
    exit(1);
}

$opts = is_array($job['options'] ?? null) ? $job['options'] : [];
$conn = is_array($job['connection'] ?? null) ? $job['connection'] : [];
$action = (string)($job['action'] ?? 'import'); // test|preview|import

OpenDcimImportJob::patch($jobId, [
    'state' => 'running',
    'phase' => $action,
    'message' => 'Starting…',
    'percent' => 5,
    'log_append' => 'Worker started for action=' . $action,
]);

try {
    $client = new OpenDcimClient($conn);
    $importOpts = array_merge([
        'mode' => OpenDcimImportService::MODE_MERGE,
        'dry_run' => true,
        'include_disposed' => false,
        'include_ports' => true,
        'include_power' => true,
        'progress' => static function (string $msg) use ($jobId): void {
            OpenDcimImportJob::patch($jobId, [
                'log_append' => $msg,
                'message' => $msg,
            ]);
        },
    ], $opts);

    $svc = new OpenDcimImportService($client, $importOpts);

    if ($action === 'test') {
        OpenDcimImportJob::patch($jobId, ['percent' => 20, 'message' => 'Testing connection…']);
        $result = $client->testConnection();
        OpenDcimImportJob::patch($jobId, [
            'state' => 'done',
            'percent' => 100,
            'message' => !empty($result['ok']) ? 'Connection OK' : 'Connection failed or partial',
            'result' => ['type' => 'test', 'data' => $result],
            'log_append' => 'Test complete',
        ]);
        exit(!empty($result['ok']) ? 0 : 1);
    }

    if ($action === 'preview') {
        $importOpts['dry_run'] = true;
        $svc = new OpenDcimImportService($client, $importOpts);
        OpenDcimImportJob::patch($jobId, ['percent' => 15, 'message' => 'Building migration preview…']);
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
                'log' => $result['log'] ?? [],
            ],
            'log_append' => 'Preview finished',
        ]);
        exit(!empty($result['ok']) ? 0 : 1);
    }

    // import (execute)
    $importOpts['dry_run'] = false;
    $svc = new OpenDcimImportService($client, $importOpts);
    OpenDcimImportJob::patch($jobId, ['percent' => 10, 'message' => 'Importing (Mode A)…']);
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
            'log' => $result['log'] ?? [],
        ],
        'log_append' => 'Import finished',
    ]);
    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable $e) {
    OpenDcimImportJob::patch($jobId, [
        'state' => 'error',
        'percent' => 100,
        'message' => 'Failed',
        'error' => $e->getMessage(),
        'log_append' => 'ERROR: ' . $e->getMessage(),
    ]);
    exit(1);
}
