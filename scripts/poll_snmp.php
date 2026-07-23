<?php
/**
 * WinDCIM SNMP poll worker — run via Windows Task Scheduler
 *
 * Example (every 5 minutes):
 *   php C:\inetpub\wwwroot\WinDCIM\scripts\poll_snmp.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once dirname(__DIR__) . '/src/App.php';
App::boot();

if (!App::isInstalled()) {
    fwrite(STDERR, "WinDCIM is not installed.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/src/Services/SnmpPoller.php';

echo '[' . date('c') . "] Starting SNMP poll...\n";
try {
    $result = SnmpPoller::pollAll();
    echo "Success: {$result['success']}, Failed: {$result['failed']}\n";
    exit($result['failed'] > 0 && $result['success'] === 0 ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
