<?php
/**
 * ColdAisle SNMP poll worker — run via Windows Task Scheduler
 *
 * Example (every 5 minutes):
 *   php C:\inetpub\wwwroot\ColdAisle\scripts\poll_snmp.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once dirname(__DIR__) . '/src/App.php';
App::boot();

if (!App::isInstalled()) {
    fwrite(STDERR, "ColdAisle is not installed.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/src/Services/SnmpPoller.php';

echo '[' . date('c') . "] Starting SNMP poll...\n";
try {
    // Flush digests whose hold window elapsed before this cycle (e.g. last run queued)
    if (class_exists('PowerAlertService')) {
        $pre = PowerAlertService::flushDigestsIfDue(false);
        if (!empty($pre['flushed'])) {
            echo "Power digest (pre): {$pre['pdu_count']} PDU(s), {$pre['alert_count']} condition(s)\n";
        }
    }

    $result = SnmpPoller::pollAll();
    echo "Success: {$result['success']}, Failed: {$result['failed']}\n";

    // After polling, flush if hold already elapsed (long cycles); otherwise next run will
    if (class_exists('PowerAlertService')) {
        $post = PowerAlertService::flushDigestsIfDue(false);
        if (!empty($post['flushed'])) {
            echo "Power digest (post): {$post['pdu_count']} PDU(s), {$post['alert_count']} condition(s)\n";
        }
    }
    exit($result['failed'] > 0 && $result['success'] === 0 ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
