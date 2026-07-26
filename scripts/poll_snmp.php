<?php
/**
 * ColdAisle SNMP poll worker — run via Windows Task Scheduler
 *
 * Register the task with scripts/Register-ColdAisle-SnmpPollTask.ps1
 * (download from Settings → SNMP schedule). Prefer a 1-minute tick;
 * enable/disable and poll interval are controlled in the web UI.
 *
 * Example:
 *   php C:\inetpub\wwwroot\ColdAisle\scripts\poll_snmp.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$argvList = $argv ?? [];
$healthOnly = in_array('--health', $argvList, true) || in_array('-h', $argvList, true);

require_once dirname(__DIR__) . '/src/App.php';
App::boot();

if (!App::isInstalled()) {
    fwrite(STDERR, "ColdAisle is not installed.\n");
    exit(1);
}

// Fast path for task registration smoke tests (no SNMP GETs)
if ($healthOnly) {
    try {
        Database::fetchValue('SELECT 1');
        $en = class_exists('SnmpSchedulerService') && SnmpSchedulerService::isEnabled() ? 'on' : 'off';
        echo "health=ok scheduler={$en}\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'health=fail ' . $e->getMessage() . "\n");
        exit(1);
    }
}

require_once dirname(__DIR__) . '/src/Services/SnmpPoller.php';
if (is_file(dirname(__DIR__) . '/src/Services/SnmpSchedulerService.php')) {
    require_once dirname(__DIR__) . '/src/Services/SnmpSchedulerService.php';
}

// Avoid multi-hour hangs when many PDUs are unreachable (CLI max)
@ini_set('max_execution_time', '900');
@ini_set('default_socket_timeout', '5');
if (function_exists('snmp_set_valueretrieval')) {
    // keep defaults; timeouts are SNMP-session specific below when available
}

echo '[' . date('c') . "] Starting SNMP poll...\n";
try {
    if (class_exists('SnmpSchedulerService') && !SnmpSchedulerService::isEnabled()) {
        $msg = 'Scheduler disabled in Settings (no work).';
        echo $msg . "\n";
        SnmpSchedulerService::recordRun(0, 0, $msg);
        exit(0);
    }

    // Flush digests whose hold window elapsed before this cycle
    if (class_exists('PowerAlertService')) {
        $pre = PowerAlertService::flushDigestsIfDue(false);
        if (!empty($pre['flushed'])) {
            echo "Power digest (pre): {$pre['pdu_count']} PDU(s), {$pre['alert_count']} condition(s)\n";
        }
    }

    $result = SnmpPoller::pollAll();
    if (!empty($result['disabled'])) {
        $msg = 'Scheduler disabled in Settings (no work).';
        echo $msg . "\n";
        if (class_exists('SnmpSchedulerService')) {
            SnmpSchedulerService::recordRun(0, 0, $msg);
        }
        exit(0);
    }

    $ok = (int)($result['success'] ?? 0);
    $fail = (int)($result['failed'] ?? 0);
    $skip = (int)($result['skipped'] ?? 0);
    echo "Success: {$ok}, Failed: {$fail}, Skipped (not due): {$skip}\n";

    if (class_exists('SnmpSchedulerService')) {
        SnmpSchedulerService::recordRun(
            $ok,
            $fail,
            "ok={$ok} fail={$fail} skip={$skip}"
        );
    }

    if (class_exists('PowerAlertService')) {
        $post = PowerAlertService::flushDigestsIfDue(false);
        if (!empty($post['flushed'])) {
            echo "Power digest (post): {$post['pdu_count']} PDU(s), {$post['alert_count']} condition(s)\n";
        }
    }
    exit($fail > 0 && $ok === 0 ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    if (class_exists('SnmpSchedulerService')) {
        try {
            SnmpSchedulerService::recordRun(0, 1, 'error: ' . substr($e->getMessage(), 0, 200));
        } catch (Throwable $ignored) {
        }
    }
    exit(1);
}
