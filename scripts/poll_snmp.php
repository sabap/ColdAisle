<?php
/**
 * ColdAisle SNMP poll worker — Windows Task Scheduler entry point.
 *
 * First lines must not depend on App bootstrap (diagnose hangs / SYSTEM ACL).
 *
 *   php poll_snmp.php
 *   php poll_snmp.php --health
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Ultra-early diagnostics (before any require / extension-heavy App.php)
// ---------------------------------------------------------------------------
$root = dirname(__DIR__);
$argvList = isset($argv) && is_array($argv) ? $argv : [];
$healthOnly = in_array('--health', $argvList, true) || in_array('-h', $argvList, true);
$pid = function_exists('getmypid') ? (int)getmypid() : 0;
$stamp = date('c');

$diagTargets = [
    $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'snmp_poll_cli.log',
    (getenv('TEMP') ?: (getenv('TMP') ?: 'C:\\Windows\\Temp')) . DIRECTORY_SEPARATOR . 'coldaisle_snmp_poll.log',
    $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'snmp_poll_cli.log',
];
$hbTargets = [
    $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'snmp_scheduler_heartbeat.txt',
    (getenv('TEMP') ?: (getenv('TMP') ?: 'C:\\Windows\\Temp')) . DIRECTORY_SEPARATOR . 'coldaisle_snmp_heartbeat.txt',
];

$earlyLog = static function (string $msg) use ($diagTargets, $stamp, $pid): void {
    $line = '[' . date('c') . "] pid={$pid} {$msg}\n";
    foreach ($diagTargets as $path) {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($path, $line, FILE_APPEND);
    }
    // Always try stdout (captured by Task Scheduler history if configured)
    echo $line;
};

$earlyHb = static function (string $summary) use ($hbTargets, $pid): void {
    $payload = json_encode([
        'at' => date('Y-m-d H:i:s'),
        'ts' => time(),
        'summary' => $summary,
        'pid' => $pid,
    ], JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return;
    }
    foreach ($hbTargets as $path) {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($path, $payload);
    }
};

$earlyLog('enter poll_snmp.php' . ($healthOnly ? ' --health' : ''));
$earlyHb('cli enter');

// Parallel pool finishes wall-clock faster, but allow long parent runs for large fleets
@ini_set('max_execution_time', $healthOnly ? '20' : '900');
@ini_set('default_socket_timeout', '3');
@ini_set('display_errors', '1');
@error_reporting(E_ALL);

// Reduce Net-SNMP MIB-directory thrash on Windows (can delay/hang CLI startup)
@putenv('MIBDIRS=');
@putenv('MIBS=');
// Light boot: skip Schema::ensure / crypto migration (CLI poll worker)
@putenv('COLDAISLE_CLI_LIGHT=1');

$earlyLog('loading App.php');
try {
    require_once $root . '/src/App.php';
} catch (Throwable $e) {
    $earlyLog('require App.php failed: ' . $e->getMessage());
    $earlyHb('require App failed: ' . $e->getMessage());
    fwrite(STDERR, 'require App.php failed: ' . $e->getMessage() . "\n");
    exit(1);
}
$earlyLog('App.php loaded, booting');

try {
    App::boot();
} catch (Throwable $e) {
    $earlyLog('boot failed: ' . $e->getMessage());
    $earlyHb('boot failed: ' . $e->getMessage());
    fwrite(STDERR, 'Boot failed: ' . $e->getMessage() . "\n");
    exit(1);
}
$earlyLog('boot ok');

if (!App::isInstalled()) {
    $earlyLog('not installed');
    $earlyHb('not installed');
    fwrite(STDERR, "ColdAisle is not installed.\n");
    exit(1);
}

if (is_file($root . '/src/Services/SnmpSchedulerService.php')) {
    require_once $root . '/src/Services/SnmpSchedulerService.php';
}

if ($healthOnly) {
    try {
        Database::fetchValue('SELECT 1');
        $en = class_exists('SnmpSchedulerService') && SnmpSchedulerService::isEnabled() ? 'on' : 'off';
        $msg = "health=ok scheduler={$en}";
        echo $msg . "\n";
        $earlyHb($msg);
        $earlyLog($msg);
        if (class_exists('SnmpSchedulerService')) {
            SnmpSchedulerService::recordRun(0, 0, $msg);
        }
        exit(0);
    } catch (Throwable $e) {
        $msg = 'health=fail ' . $e->getMessage();
        fwrite(STDERR, $msg . "\n");
        $earlyHb($msg);
        $earlyLog($msg);
        exit(1);
    }
}

require_once $root . '/src/Services/SnmpPoller.php';

// Single-instance lock
$lockFile = $root . '/storage/tmp/snmp_poll.lock';
if (!is_dir(dirname($lockFile))) {
    @mkdir(dirname($lockFile), 0775, true);
}
$lockFp = @fopen($lockFile, 'c+');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    $msg = 'skipped: another poll_snmp instance still running';
    $earlyLog($msg);
    $earlyHb($msg);
    if (class_exists('SnmpSchedulerService')) {
        try {
            SnmpSchedulerService::recordRun(0, 0, $msg);
        } catch (Throwable $e) {
        }
    }
    if (is_resource($lockFp)) {
        fclose($lockFp);
    }
    exit(0);
}
ftruncate($lockFp, 0);
fwrite($lockFp, (string)$pid . ' ' . date('c'));
fflush($lockFp);

$earlyHb('poll cycle begin');
$earlyLog('poll cycle begin');
if (class_exists('SnmpSchedulerService')) {
    try {
        SnmpSchedulerService::recordRun(0, 0, 'running…');
    } catch (Throwable $e) {
        $earlyLog('recordRun(start) failed: ' . $e->getMessage());
    }
}

echo '[' . date('c') . "] Starting poll worker...\n";

try {
    $ok = 0;
    $fail = 0;
    $skip = 0;
    $snmpDisabled = class_exists('SnmpSchedulerService') && !SnmpSchedulerService::isEnabled();

    // --- ICMP reachability (devices + PDUs with icmp_monitor) ---
    // Runs even when SNMP schedule is off so "Monitor via ICMP" still works.
    $icmpSummary = '';
    if (is_file($root . '/src/Services/IcmpMonitorService.php')) {
        require_once $root . '/src/Services/IcmpMonitorService.php';
        try {
            $icmp = IcmpMonitorService::pollAll();
            $icmpSummary = 'icmp checked=' . (int)($icmp['checked'] ?? 0)
                . ' up=' . (int)($icmp['up'] ?? 0)
                . ' down=' . (int)($icmp['down'] ?? 0);
            echo "ICMP: {$icmpSummary}\n";
            $earlyLog($icmpSummary);
        } catch (Throwable $e) {
            $earlyLog('icmp: ' . $e->getMessage());
            echo 'ICMP error: ' . $e->getMessage() . "\n";
        }
    }

    // Disposal due-soon digests (G-B5) — independent of SNMP schedule
    $disposalSummary = '';
    if (class_exists('ProductMailService')) {
        try {
            $disp = ProductMailService::processDisposalReminders(false);
            $disposalSummary = 'disposal mail due=' . (int)($disp['due'] ?? 0)
                . ' sent=' . (int)($disp['sent'] ?? 0);
            if (!empty($disp['message'])) {
                $disposalSummary .= ' (' . $disp['message'] . ')';
            }
            echo "Disposal: {$disposalSummary}\n";
            $earlyLog($disposalSummary);
        } catch (Throwable $e) {
            $earlyLog('disposal mail: ' . $e->getMessage());
            echo 'Disposal mail error: ' . $e->getMessage() . "\n";
        }
    }

    // Warranty expiration digests (G-B3)
    $warrantySummary = '';
    if (class_exists('ProductMailService')) {
        try {
            $war = ProductMailService::processWarrantyReminders(false);
            $warrantySummary = 'warranty mail due=' . (int)($war['due'] ?? 0)
                . ' sent=' . (int)($war['sent'] ?? 0);
            if (!empty($war['message'])) {
                $warrantySummary .= ' (' . $war['message'] . ')';
            }
            echo "Warranty: {$warrantySummary}\n";
            $earlyLog($warrantySummary);
        } catch (Throwable $e) {
            $earlyLog('warranty mail: ' . $e->getMessage());
            echo 'Warranty mail error: ' . $e->getMessage() . "\n";
        }
    }

    if ($snmpDisabled) {
        $msg = 'SNMP scheduler disabled in Settings.'
            . ($icmpSummary !== '' ? ' ' . $icmpSummary : ' No SNMP work.')
            . ($disposalSummary !== '' ? ' · ' . $disposalSummary : '')
            . ($warrantySummary !== '' ? ' · ' . $warrantySummary : '');
        echo $msg . "\n";
        $earlyHb($msg);
        $earlyLog($msg);
        if (class_exists('SnmpSchedulerService')) {
            SnmpSchedulerService::recordRun(0, 0, $msg);
        }
        exit(0);
    }

    if (class_exists('PowerAlertService')) {
        try {
            $pre = PowerAlertService::flushDigestsIfDue(false);
            if (!empty($pre['flushed'])) {
                echo "Power digest (pre): {$pre['pdu_count']} PDU(s), {$pre['alert_count']} condition(s)\n";
            }
        } catch (Throwable $e) {
            $earlyLog('digest pre: ' . $e->getMessage());
        }
    }

    $result = SnmpPoller::pollAll();
    if (!empty($result['disabled'])) {
        $msg = 'SNMP scheduler disabled in Settings.'
            . ($icmpSummary !== '' ? ' ' . $icmpSummary : '');
        echo $msg . "\n";
        $earlyHb($msg);
        $earlyLog($msg);
        if (class_exists('SnmpSchedulerService')) {
            SnmpSchedulerService::recordRun(0, 0, $msg);
        }
        exit(0);
    }

    $ok = (int)($result['success'] ?? 0);
    $fail = (int)($result['failed'] ?? 0);
    $skip = (int)($result['skipped'] ?? 0);
    $mode = (string)($result['mode'] ?? '');
    $workers = (int)($result['workers'] ?? 0);
    $summary = "snmp ok={$ok} fail={$fail} skip={$skip}"
        . ($workers > 0 ? " workers={$workers}" : '')
        . ($mode !== '' ? " mode={$mode}" : '')
        . ($icmpSummary !== '' ? ' · ' . $icmpSummary : '');
    echo "SNMP Success: {$ok}, Failed: {$fail}, Skipped (not due): {$skip}"
        . ($workers > 0 ? ", Workers: {$workers}" : '')
        . ($mode !== '' ? " ({$mode})" : '')
        . "\n";
    $earlyHb($summary);
    $earlyLog($summary);

    if (class_exists('SnmpSchedulerService')) {
        SnmpSchedulerService::recordRun($ok, $fail, $summary);
    }

    if (class_exists('PowerAlertService')) {
        try {
            $post = PowerAlertService::flushDigestsIfDue(false);
            if (!empty($post['flushed'])) {
                echo "Power digest (post): {$post['pdu_count']} PDU(s), {$post['alert_count']} condition(s)\n";
            }
        } catch (Throwable $e) {
            $earlyLog('digest post: ' . $e->getMessage());
        }
    }

    // Env thresholds + stale/offline sensors (after SNMP cycle)
    if (class_exists('EnvSensorAlertService')) {
        try {
            $envRun = EnvSensorAlertService::runScheduledChecks();
            $envMsg = sprintf(
                'env alerts threshold=%d/%d stale=%d/%d',
                (int)($envRun['threshold_alerted'] ?? 0),
                (int)($envRun['threshold_checked'] ?? 0),
                (int)($envRun['stale_alerted'] ?? 0),
                (int)($envRun['stale_checked'] ?? 0)
            );
            echo $envMsg . "\n";
            $earlyLog($envMsg);
        } catch (Throwable $e) {
            $earlyLog('env alerts: ' . $e->getMessage());
        }
    }

    // Occasional storage prune (at most every 12h when auto-enabled)
    if (class_exists('StorageHousekeepingService')) {
        try {
            $hk = StorageHousekeepingService::maybeRunScheduled();
            if (is_array($hk) && !empty($hk['message'])) {
                $earlyLog('housekeeping: ' . $hk['message']);
            }
        } catch (Throwable $e) {
            $earlyLog('housekeeping: ' . $e->getMessage());
        }
    }

    // Note: disposal due-soon digests already ran earlier (even if SNMP was off)

    exit($fail > 0 && $ok === 0 ? 2 : 0);
} catch (Throwable $e) {
    $msg = 'error: ' . $e->getMessage();
    fwrite(STDERR, $msg . "\n");
    $earlyHb($msg);
    $earlyLog($msg);
    if (class_exists('SnmpSchedulerService')) {
        try {
            SnmpSchedulerService::recordRun(0, 1, substr($msg, 0, 500));
        } catch (Throwable $ignored) {
        }
    }
    exit(1);
} finally {
    if (isset($lockFp) && is_resource($lockFp)) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
    @unlink($lockFile);
}
