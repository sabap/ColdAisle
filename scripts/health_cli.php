<?php
/**
 * Lightweight CLI SQL health — no SNMP.
 * Verbose + short connection timeout so hangs are visible and fail fast.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);

$say = static function (string $msg) use ($root): void {
    $line = '[' . date('H:i:s') . "] {$msg}\n";
    echo $line;
    if (function_exists('fflush')) {
        @fflush(STDOUT);
    }
    $paths = [
        $root . '/storage/logs/snmp_poll_cli.log',
        (getenv('TEMP') ?: 'C:/Windows/Temp') . '/coldaisle_snmp_poll.log',
    ];
    foreach ($paths as $p) {
        $d = dirname($p);
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        @file_put_contents($p, '[' . date('c') . "] health_cli: {$msg}\n", FILE_APPEND);
    }
};

$say('start pid=' . getmypid());

$configPath = $root . '/config/config.php';
if (!is_file($configPath)) {
    $say('FAIL: config/config.php missing');
    exit(1);
}
$say('config file found');

/** @var array $config */
$config = require $configPath;
$db = is_array($config['database'] ?? null) ? $config['database'] : [];
$host = (string)($db['host'] ?? 'localhost');
$port = (int)($db['port'] ?? 1433);
$name = (string)($db['database'] ?? 'ColdAisle');
$user = (string)($db['username'] ?? '');
$pass = (string)($db['password'] ?? '');
$encryptOn = !empty($db['encrypt']);
$trustOn = !empty($db['trust_server_certificate']);
$encrypt = $encryptOn ? 'yes' : 'no';
$trust = $trustOn ? 'yes' : 'no';
$odbcDriver = (string)($db['odbc_driver'] ?? 'ODBC Driver 18 for SQL Server');

$server = $host;
if ($port > 0 && strpos($host, '\\') === false && strpos($host, ',') === false) {
    $server = $host . ',' . $port;
}

$authMode = ($user === '') ? 'Windows auth (current process identity)' : ('SQL auth user=' . $user);
$say("db host={$host} server={$server} database={$name} encrypt={$encrypt} trust={$trust}");
$say("auth={$authMode}");
$say('pdo drivers=' . implode(',', PDO::getAvailableDrivers()));

$loginTimeout = 5;

try {
    $drivers = PDO::getAvailableDrivers();
    $pdo = null;

    if (in_array('odbc', $drivers, true)) {
        // ODBC: Connection Timeout is the reliable fail-fast knob (seconds)
        $dsn = "odbc:Driver={{$odbcDriver}};"
            . "Server={$server};"
            . "Database={$name};"
            . "Encrypt={$encrypt};"
            . "TrustServerCertificate={$trust};"
            . "Connection Timeout={$loginTimeout};"
            . "LoginTimeout={$loginTimeout};"
            . "Timeout={$loginTimeout}";
        $say("connecting via pdo_odbc (timeout {$loginTimeout}s)...");
        $say('dsn=' . preg_replace('/Password=[^;]*/i', 'Password=***', $dsn));
        $t0 = microtime(true);
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $say(sprintf('pdo connected in %.2fs', microtime(true) - $t0));
    } elseif (in_array('sqlsrv', $drivers, true)) {
        $dsn = "sqlsrv:Server={$server};Database={$name};Encrypt={$encrypt};TrustServerCertificate={$trust};LoginTimeout={$loginTimeout}";
        $say("connecting via pdo_sqlsrv (LoginTimeout {$loginTimeout}s)...");
        $t0 = microtime(true);
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $say(sprintf('pdo connected in %.2fs', microtime(true) - $t0));
    } else {
        throw new RuntimeException('No pdo_odbc or pdo_sqlsrv in this process');
    }

    $say('SELECT 1...');
    $v = $pdo->query('SELECT 1 AS n')->fetch(PDO::FETCH_ASSOC);
    if (!$v) {
        throw new RuntimeException('SELECT 1 returned no row');
    }
    $say('SELECT 1 ok');

    $sched = 'unknown';
    try {
        $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $st->execute(['snmp_scheduler_enabled']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $sched = ($row && ($row['setting_value'] ?? '') === '1') ? 'on' : 'off';
        $say('scheduler setting=' . $sched);
    } catch (Throwable $e) {
        $say('scheduler setting unreadable: ' . $e->getMessage());
    }

    $summary = "health=ok scheduler={$sched}";
    $hb = json_encode([
        'at' => date('Y-m-d H:i:s'),
        'ts' => time(),
        'summary' => $summary,
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

    try {
        $now = date('Y-m-d H:i:s');
        foreach ([
            'snmp_scheduler_last_run_at' => $now,
            'snmp_scheduler_last_result' => $summary,
        ] as $k => $val) {
            $chk = $pdo->prepare('SELECT 1 FROM settings WHERE setting_key = ?');
            $chk->execute([$k]);
            if ($chk->fetch()) {
                $up = $pdo->prepare('UPDATE settings SET setting_value = ?, updated_at = SYSUTCDATETIME() WHERE setting_key = ?');
                $up->execute([$val, $k]);
            } else {
                $ins = $pdo->prepare('INSERT INTO settings (setting_key, setting_value, category) VALUES (?, ?, ?)');
                $ins->execute([$k, $val, 'snmp']);
            }
        }
        $say('settings heartbeat written');
    } catch (Throwable $e) {
        $say('settings write skipped: ' . $e->getMessage());
    }

    echo $summary . "\n";
    $say('done ' . $summary);
    exit(0);
} catch (Throwable $e) {
    $msg = 'health=fail ' . $e->getMessage();
    echo $msg . "\n";
    $say($msg);
    $say('HINT: If this is a timeout, SQL is not reachable from this Windows account.');
    $say('HINT: Web uses the IIS app-pool identity; CLI uses your user (or SYSTEM for the task).');
    $say('HINT: Prefer SQL authentication in config.php for scheduled tasks, or grant SYSTEM a SQL login.');
    fwrite(STDERR, $msg . "\n");
    exit(1);
}
