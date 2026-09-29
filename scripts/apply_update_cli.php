<?php
/**
 * Detached update worker. IIS FastCGI kills a long backup inside the web request;
 * this process is started with php.exe and is not subject to that timer.
 *
 * Usage: php scripts/apply_update_cli.php [version]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/src/App.php';

$version = isset($argv[1]) ? trim((string)$argv[1]) : '';
UpdateService::writeApplyStatus([
    'ok' => false,
    'state' => 'running',
    'phase' => 'worker',
    'version' => $version !== '' ? $version : null,
    'message' => 'Update process started. Preparing backup…',
]);

try {
    App::boot();
} catch (Throwable $e) {
    UpdateService::writeApplyStatus([
        'ok' => false,
        'state' => 'failed',
        'phase' => 'failed',
        'version' => $version !== '' ? $version : null,
        'message' => 'Update process failed to start: ' . $e->getMessage(),
    ]);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

try {
    $result = UpdateService::applyUpdate($version !== '' ? $version : null);
    exit(empty($result['ok']) ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
