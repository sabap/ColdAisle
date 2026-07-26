<?php
/**
 * Lightweight CLI SQL health — no SNMP.
 * Unbuffered output so hangs show the last successful step.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

// Force line-by-line output (otherwise PowerShell/cmd show nothing until PHP exits)
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    @ob_end_flush();
}
ob_implicit_flush(true);

$root = dirname(__DIR__);

$say = static function (string $msg) use ($root): void {
    $line = '[' . date('H:i:s') . "] {$msg}\n";
    // fwrite is less buffered than echo in some SAPIs
    fwrite(STDOUT, $line);
    @fflush(STDOUT);
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
    $say('FAIL: config/config.php missing at ' . $configPath);
    exit(1);
}
$say('config file found');

/** @var array $config */
$config = require $configPath;
$say('config loaded');

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

// Named instances: no ,port
$tcpHost = $host;
$tcpPort = $port > 0 ? $port : 1433;
if (strpos($host, '\\') !== false) {
    // named instance — TCP probe uses 1433 as guess only
    $tcpPort = 1433;
}
if (strpos($host, ',') !== false) {
    $parts = explode(',', $host, 2);
    $tcpHost = $parts[0];
    if (isset($parts[1]) && is_numeric(trim($parts[1]))) {
        $tcpPort = (int)trim($parts[1]);
    }
}

$server = $host;
if ($port > 0 && strpos($host, '\\') === false && strpos($host, ',') === false) {
    $server = $host . ',' . $port;
}

$authMode = ($user === '') ? 'Windows auth (this process identity)' : ('SQL auth user=' . $user);
$say("db host={$host} server={$server} database={$name}");
$say("encrypt={$encrypt} trust_server_certificate={$trust} odbc_driver={$odbcDriver}");
$say("auth={$authMode}");
$say('pdo drivers=' . implode(',', PDO::getAvailableDrivers()));

// --- TCP probe (fails in 3s if host/port blocked) ---
$say("TCP probe {$tcpHost}:{$tcpPort} (3s)...");
$errno = 0;
$errstr = '';
$t0 = microtime(true);
$sock = @stream_socket_client(
    "tcp://{$tcpHost}:{$tcpPort}",
    $errno,
    $errstr,
    3.0
);
if ($sock === false) {
    $say("TCP FAIL after " . sprintf('%.2f', microtime(true) - $t0) . "s: [{$errno}] {$errstr}");
    $say('SQL port is not reachable from this machine/account. Fix host/firewall/SQL TCP.');
    echo "health=fail tcp {$tcpHost}:{$tcpPort} {$errstr}\n";
    exit(1);
}
fclose($sock);
$say('TCP ok in ' . sprintf('%.2f', microtime(true) - $t0) . 's');

$loginTimeout = 5;

try {
    $drivers = PDO::getAvailableDrivers();
    $pdo = null;

    if (in_array('sqlsrv', $drivers, true)) {
        $dsn = "sqlsrv:Server={$server};Database={$name};Encrypt={$encrypt};TrustServerCertificate={$trust};LoginTimeout={$loginTimeout}";
        $say("PDO sqlsrv connect (LoginTimeout={$loginTimeout})...");
        $t0 = microtime(true);
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $say('PDO connected in ' . sprintf('%.2f', microtime(true) - $t0) . 's (sqlsrv)');
    } elseif (in_array('odbc', $drivers, true)) {
        // MARS_Connection / retries reduced; Connection Timeout is critical for ODBC
        $dsn = "odbc:Driver={{$odbcDriver}};"
            . "Server={$server};"
            . "Database={$name};"
            . "Encrypt={$encrypt};"
            . "TrustServerCertificate={$trust};"
            . "Connection Timeout={$loginTimeout};"
            . "Login Timeout={$loginTimeout};"
            . "Connect Retry Count=1;"
            . "Connect Retry Interval=1";
        $say("PDO odbc connect (Connection Timeout={$loginTimeout})...");
        $say('dsn(no secret)=' . $dsn);
        $t0 = microtime(true);
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $say('PDO connected in ' . sprintf('%.2f', microtime(true) - $t0) . 's (odbc)');
    } else {
        throw new RuntimeException('No pdo_odbc or pdo_sqlsrv loaded');
    }

    $say('SELECT 1...');
    $pdo->query('SELECT 1')->fetch();
    $say('SELECT 1 ok');

    $sched = 'unknown';
    try {
        $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $st->execute(['snmp_scheduler_enabled']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $sched = ($row && ($row['setting_value'] ?? '') === '1') ? 'on' : 'off';
        $say('scheduler=' . $sched);
    } catch (Throwable $e) {
        $say('scheduler unread: ' . $e->getMessage());
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
                $up = $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
                $up->execute([$val, $k]);
            } else {
                $ins = $pdo->prepare('INSERT INTO settings (setting_key, setting_value, category) VALUES (?, ?, ?)');
                $ins->execute([$k, $val, 'snmp']);
            }
        }
        $say('settings heartbeat written');
    } catch (Throwable $e) {
        $say('settings write skip: ' . $e->getMessage());
    }

    fwrite(STDOUT, $summary . "\n");
    @fflush(STDOUT);
    $say('done');
    exit(0);
} catch (Throwable $e) {
    $msg = 'health=fail ' . $e->getMessage();
    fwrite(STDOUT, $msg . "\n");
    $say($msg);
    $say('HINT: Web may work via app-pool identity while CLI uses your user or SYSTEM.');
    $say('HINT: Use SQL auth in config.php for Task Scheduler (SYSTEM), or grant SYSTEM a SQL login.');
    exit(1);
}
