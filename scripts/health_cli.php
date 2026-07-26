<?php
/**
 * Lightweight CLI health check — no SNMP extension required.
 * Used by run_poll_snmp.cmd --health so registration is not blocked by
 * Net-SNMP init or Schema::ensure().
 *
 *   php -n -d extension_dir=... -d extension=pdo_sqlsrv health_cli.php
 *   (or via run_poll_snmp.cmd --health)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
$log = static function (string $msg) use ($root): void {
    $line = '[' . date('c') . "] health_cli: {$msg}\n";
    $paths = [
        $root . '/storage/logs/snmp_poll_cli.log',
        (getenv('TEMP') ?: 'C:/Windows/Temp') . '/coldaisle_snmp_poll.log',
    ];
    foreach ($paths as $p) {
        $d = dirname($p);
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        @file_put_contents($p, $line, FILE_APPEND);
    }
    echo $line;
    if (function_exists('flush')) {
        @ob_flush();
        @flush();
    }
};

$log('start');

$configPath = $root . '/config/config.php';
if (!is_file($configPath)) {
    $log('fail: config/config.php missing');
    fwrite(STDERR, "health=fail config missing\n");
    exit(1);
}

/** @var array $config */
$config = require $configPath;
$db = is_array($config['database'] ?? null) ? $config['database'] : [];
$host = (string)($db['host'] ?? 'localhost');
$port = (int)($db['port'] ?? 1433);
$name = (string)($db['database'] ?? 'ColdAisle');
$user = (string)($db['username'] ?? '');
$pass = (string)($db['password'] ?? '');
$encrypt = !empty($db['encrypt']) ? 'yes' : 'no';
$trust = !empty($db['trust_server_certificate']) ? 'yes' : 'no';
$odbcDriver = (string)($db['odbc_driver'] ?? 'ODBC Driver 18 for SQL Server');

$server = $host;
if ($port > 0 && strpos($host, '\\') === false && strpos($host, ',') === false) {
    $server = $host . ',' . $port;
}

$drivers = PDO::getAvailableDrivers();
$log('pdo drivers: ' . implode(',', $drivers));

try {
    if (in_array('sqlsrv', $drivers, true)) {
        $dsn = "sqlsrv:Server={$server};Database={$name};Encrypt={$encrypt};TrustServerCertificate={$trust};LoginTimeout=5;LoginTimeout=5";
        // LoginTimeout once is enough
        $dsn = "sqlsrv:Server={$server};Database={$name};Encrypt={$encrypt};TrustServerCertificate={$trust};LoginTimeout=5";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    } elseif (in_array('odbc', $drivers, true)) {
        $dsn = "odbc:Driver={{$odbcDriver}};Server={$server};Database={$name};Encrypt={$encrypt};TrustServerCertificate={$trust};LoginTimeout=5";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } else {
        throw new RuntimeException('No pdo_sqlsrv or pdo_odbc driver loaded in this PHP process');
    }

    $v = $pdo->query('SELECT 1 AS n')->fetch(PDO::FETCH_ASSOC);
    if (!$v) {
        throw new RuntimeException('SELECT 1 returned no row');
    }
    $log('sql ok');

    // Optional: scheduler flag (do not fail health if settings table missing)
    $sched = 'unknown';
    try {
        $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $st->execute(['snmp_scheduler_enabled']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $sched = ($row && ($row['setting_value'] ?? '') === '1') ? 'on' : 'off';
    } catch (Throwable $e) {
        $sched = 'unknown';
    }

    // Heartbeat files so Settings can show Active without full App boot
    $hb = json_encode([
        'at' => date('Y-m-d H:i:s'),
        'ts' => time(),
        'summary' => "health=ok scheduler={$sched}",
        'pid' => getmypid(),
    ], JSON_UNESCAPED_SLASHES);
    foreach ([
        $root . '/storage/logs/snmp_scheduler_heartbeat.txt',
        (getenv('TEMP') ?: 'C:/Windows/Temp') . '/coldaisle_snmp_heartbeat.txt',
    ] as $hp) {
        $d = dirname($hp);
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        @file_put_contents($hp, $hb);
    }

    // Best-effort settings write (same table UI reads)
    try {
        $now = date('Y-m-d H:i:s');
        $keys = [
            'snmp_scheduler_last_run_at' => $now,
            'snmp_scheduler_last_result' => "health=ok scheduler={$sched}",
        ];
        foreach ($keys as $k => $val) {
            $chk = $pdo->prepare('SELECT 1 FROM settings WHERE setting_key = ?');
            $chk->execute([$k]);
            if ($chk->fetch()) {
                $up = $pdo->prepare('UPDATE settings SET setting_value = ?, updated_at = ? WHERE setting_key = ?');
                $up->execute([$val, $now, $k]);
            } else {
                $ins = $pdo->prepare('INSERT INTO settings (setting_key, setting_value, category) VALUES (?, ?, ?)');
                $ins->execute([$k, $val, 'snmp']);
            }
        }
    } catch (Throwable $e) {
        $log('settings write skipped: ' . $e->getMessage());
    }

    echo "health=ok scheduler={$sched}\n";
    $log("done health=ok scheduler={$sched}");
    exit(0);
} catch (Throwable $e) {
    $msg = 'health=fail ' . $e->getMessage();
    echo $msg . "\n";
    $log($msg);
    fwrite(STDERR, $msg . "\n");
    exit(1);
}
