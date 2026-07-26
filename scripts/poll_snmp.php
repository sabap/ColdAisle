<?php
/**
 * ColdAisle SNMP poll worker — run via Windows Task Scheduler
 *
 * Register with scripts/Register-ColdAisle-SnmpPollTask.ps1
 * (download from Settings → SNMP schedule). Prefer a 1-minute tick;
 * enable/disable and intervals are controlled in the web UI.
 *
 * Example:
 *   php C:\inetpub\wwwroot\ColdAisle\scripts\poll_snmp.php
 *   php …\poll_snmp.php --health
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
$argvList = $argv ?? [];
$healthOnly = in_array('--health', $argvList, true) || in_array('-h', $argvList, true);

// File log + heartbeat work even if SQL is slow (SYSTEM account debugging)
$logDir = $root . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$cliLog = $logDir . '/snmp_poll_cli.log';
$hbFile = $logDir . '/snmp_scheduler_heartbeat.txt';
$lockFile = $root . '/storage/tmp/snmp_poll.lock';

$cliLogLine = static function (string $msg) use ($cliLog): void {
    $line = '[' . date('c') . '] ' . $msg . "\n";
    @file_put_contents($cliLog, $line, FILE_APPEND);
    echo $line;
};

$writeHbFile = static function (string $summary) use ($hbFile): void {
    @file_put_contents(
        $hbFile,
        json_encode([
            'at' => date('Y-m-d H:i:s'),
            'ts' => time(),
            'summary' => $summary,
            'pid' => getmypid(),
        ], JSON_UNESCAPED_SLASHES)
    );
};

$writeHbFile('cli starting');
$cliLogLine('poll_snmp start pid=' . getmypid() . ($healthOnly ? ' --health' : ''));

@ini_set('max_execution_time', $healthOnly ? '20' : '300');
@ini_set('default_socket_timeout', '3');
// Net-SNMP library defaults can be long; discourage multi-minute hangs
@putenv('SNMPCONFPATH=');

require_once $root . '/src/App.php';

try {
    App::boot();
} catch (Throwable $e) {
    $cliLogLine('boot failed: ' . $e->getMessage());
    $writeHbFile('boot failed: ' . $e->getMessage());
    fwrite(STDERR, 'Boot failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!App::isInstalled()) {
    $cliLogLine('not installed');
    $writeHbFile('not installed');
    fwrite(STDERR, "ColdAisle is not installed.\n");
    exit(1);
}

if (is_file($root . '/src/Services/SnmpSchedulerService.php')) {
    require_once $root . '/src/Services/SnmpSchedulerService.php';
}

// Fast path for task registration smoke tests (no SNMP GETs)
if ($healthOnly) {
    try {
        Database::fetchValue('SELECT 1');
        $en = class_exists('SnmpSchedulerService') && SnmpSchedulerService::isEnabled() ? 'on' : 'off';
        $msg = "health=ok scheduler={$en}";
        echo $msg . "\n";
        $writeHbFile($msg);
        if (class_exists('SnmpSchedulerService')) {
            // Do not mark full poll metrics; still prove worker can write settings
            SnmpSchedulerService::recordRun(0, 0, $msg);
        }
        $cliLogLine($msg);
        exit(0);
    } catch (Throwable $e) {
        $msg = 'health=fail ' . $e->getMessage();
        fwrite(STDERR, $msg . "\n");
        $writeHbFile($msg);
        $cliLogLine($msg);
        exit(1);
    }
}

require_once $root . '/src/Services/SnmpPoller.php';

// Single-instance lock: avoid stacking hung polls every minute
if (!is_dir(dirname($lockFile))) {
    @mkdir(dirname($lockFile), 0775, true);
}
$lockFp = @fopen($lockFile, 'c+');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    $msg = 'skipped: another poll_snmp instance still running';
    $cliLogLine($msg);
    $writeHbFile($msg);
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
fwrite($lockFp, (string)getmypid() . ' ' . date('c'));
fflush($lockFp);

// Prove the worker is alive BEFORE long SNMP work (fixes "Waiting for Windows task")
$writeHbFile('poll cycle begin');
if (class_exists('SnmpSchedulerService')) {
    try {
        SnmpSchedulerService::recordRun(0, 0, 'running…');
    } catch (Throwable $e) {
        $cliLogLine('recordRun(start) failed: ' . $e->getMessage());
    }
}

$cliLogLine('Starting SNMP poll...');
echo '[' . date('c') . "] Starting SNMP poll...\n";

try {
    if (class_exists('SnmpSchedulerService') && !SnmpSchedulerService::isEnabled()) {
        $msg = 'Scheduler disabled in Settings (no work).';
        echo $msg . "\n";
        $writeHbFile($msg);
        SnmpSchedulerService::recordRun(0, 0, $msg);
        $cliLogLine($msg);
        exit(0);
    }

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
        $writeHbFile($msg);
        if (class_exists('SnmpSchedulerService')) {
            SnmpSchedulerService::recordRun(0, 0, $msg);
        }
        $cliLogLine($msg);
        exit(0);
    }

    $ok = (int)($result['success'] ?? 0);
    $fail = (int)($result['failed'] ?? 0);
    $skip = (int)($result['skipped'] ?? 0);
    $summary = "ok={$ok} fail={$fail} skip={$skip}";
    echo "Success: {$ok}, Failed: {$fail}, Skipped (not due): {$skip}\n";
    $writeHbFile($summary);
    $cliLogLine($summary);

    if (class_exists('SnmpSchedulerService')) {
        SnmpSchedulerService::recordRun($ok, $fail, $summary);
    }

    if (class_exists('PowerAlertService')) {
        $post = PowerAlertService::flushDigestsIfDue(false);
        if (!empty($post['flushed'])) {
            echo "Power digest (post): {$post['pdu_count']} PDU(s), {$post['alert_count']} condition(s)\n";
        }
    }
    exit($fail > 0 && $ok === 0 ? 2 : 0);
} catch (Throwable $e) {
    $msg = 'error: ' . $e->getMessage();
    fwrite(STDERR, $msg . "\n");
    $writeHbFile($msg);
    $cliLogLine($msg);
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
