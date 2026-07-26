<?php
// ColdAisle CLI SQL health (no SNMP). First actions write a marker file so hangs are locatable.
declare(strict_types=1);

// Absolute first action — before anything that can hang
$__root = dirname(__DIR__);
$__marker = $__root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'health_last_step.txt';
@mkdir(dirname($__marker), 0775, true);
@file_put_contents($__marker, date('c') . " entered health_cli.php pid=" . getmypid() . "\n");

if (PHP_SAPI !== 'cli') {
    @file_put_contents($__marker, date('c') . " not cli\n", FILE_APPEND);
    exit(1);
}

@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    @ob_end_flush();
}
ob_implicit_flush(true);

$root = $__root;
$marker = $__marker;

$say = static function (string $msg) use ($root, $marker): void {
    $line = '[' . date('H:i:s') . "] {$msg}\n";
    @file_put_contents($marker, date('c') . ' ' . $msg . "\n", FILE_APPEND);
    fwrite(STDOUT, $line);
    @fflush(STDOUT);
    foreach ([
        $root . '/storage/logs/snmp_poll_cli.log',
        (getenv('TEMP') ?: 'C:/Windows/Temp') . '/coldaisle_snmp_poll.log',
    ] as $p) {
        $d = dirname($p);
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        @file_put_contents($p, '[' . date('c') . "] health_cli: {$msg}\n", FILE_APPEND);
    }
};

$say('start');

$configPath = $root . '/config/config.php';
if (!is_file($configPath)) {
    $say('FAIL: missing ' . $configPath);
    fwrite(STDOUT, "health=fail no config\n");
    exit(1);
}
$say('config present');

$config = require $configPath;
$say('config loaded');

$db = is_array($config['database'] ?? null) ? $config['database'] : [];
$host = (string)($db['host'] ?? 'localhost');
$port = (int)($db['port'] ?? 1433);
$name = (string)($db['database'] ?? 'ColdAisle');
$user = (string)($db['username'] ?? '');
$pass = (string)($db['password'] ?? '');
$encrypt = !empty($db['encrypt']) ? 'yes' : 'no';
$trust = !empty($db['trust_server_certificate']) ? 'yes' : 'no';
$odbcDriver = (string)($db['odbc_driver'] ?? 'ODBC Driver 18 for SQL Server');

$tcpHost = $host;
$tcpPort = ($port > 0) ? $port : 1433;
if (strpos($host, ',') !== false) {
    [$tcpHost, $p2] = array_map('trim', explode(',', $host, 2));
    if (is_numeric($p2)) {
        $tcpPort = (int)$p2;
    }
}
$server = $host;
if ($port > 0 && strpos($host, '\\') === false && strpos($host, ',') === false) {
    $server = $host . ',' . $port;
}

$say("db host={$host} server={$server} database={$name} encrypt={$encrypt} trust={$trust}");
$say(($user === '' ? 'auth=Windows (process identity)' : 'auth=SQL user=' . $user));
$say('drivers=' . implode(',', PDO::getAvailableDrivers()));

// TCP probe — 3 second hard timeout
$say("TCP {$tcpHost}:{$tcpPort} ...");
$errno = 0;
$errstr = '';
$sock = @stream_socket_client("tcp://{$tcpHost}:{$tcpPort}", $errno, $errstr, 3.0);
if ($sock === false) {
    $say("TCP FAIL [{$errno}] {$errstr}");
    fwrite(STDOUT, "health=fail tcp {$tcpHost}:{$tcpPort} {$errstr}\n");
    exit(1);
}
fclose($sock);
$say('TCP ok');

$loginTimeout = 5;
$drivers = PDO::getAvailableDrivers();

try {
    if (in_array('sqlsrv', $drivers, true)) {
        $dsn = "sqlsrv:Server={$server};Database={$name};Encrypt={$encrypt};TrustServerCertificate={$trust};LoginTimeout={$loginTimeout}";
        $say('PDO sqlsrv connecting...');
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $say('PDO sqlsrv ok');
    } elseif (in_array('odbc', $drivers, true)) {
        $dsn = "odbc:Driver={{$odbcDriver}};Server={$server};Database={$name};"
            . "Encrypt={$encrypt};TrustServerCertificate={$trust};"
            . "Connection Timeout={$loginTimeout};Login Timeout={$loginTimeout};"
            . "Connect Retry Count=0";
        $say('PDO odbc connecting...');
        $say('dsn=' . $dsn);
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $say('PDO odbc ok');
    } else {
        throw new RuntimeException('no pdo_odbc/sqlsrv');
    }

    $pdo->query('SELECT 1');
    $say('SELECT 1 ok');

    $sched = 'unknown';
    try {
        $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $st->execute(['snmp_scheduler_enabled']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $sched = ($row && ($row['setting_value'] ?? '') === '1') ? 'on' : 'off';
    } catch (Throwable $e) {
        $say('settings read: ' . $e->getMessage());
    }

    $summary = "health=ok scheduler={$sched}";
    $hb = json_encode(['at' => date('Y-m-d H:i:s'), 'ts' => time(), 'summary' => $summary, 'pid' => getmypid()]);
    foreach ([
        $root . '/storage/logs/snmp_scheduler_heartbeat.txt',
        (getenv('TEMP') ?: 'C:/Windows/Temp') . '/coldaisle_snmp_heartbeat.txt',
    ] as $hp) {
        @mkdir(dirname($hp), 0775, true);
        @file_put_contents($hp, $hb);
    }

    try {
        $now = date('Y-m-d H:i:s');
        foreach (['snmp_scheduler_last_run_at' => $now, 'snmp_scheduler_last_result' => $summary] as $k => $val) {
            $chk = $pdo->prepare('SELECT 1 FROM settings WHERE setting_key = ?');
            $chk->execute([$k]);
            if ($chk->fetch()) {
                $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?')->execute([$val, $k]);
            } else {
                $pdo->prepare('INSERT INTO settings (setting_key, setting_value, category) VALUES (?,?,?)')
                    ->execute([$k, $val, 'snmp']);
            }
        }
        $say('settings updated');
    } catch (Throwable $e) {
        $say('settings skip: ' . $e->getMessage());
    }

    fwrite(STDOUT, $summary . "\n");
    $say('done');
    exit(0);
} catch (Throwable $e) {
    $msg = 'health=fail ' . $e->getMessage();
    fwrite(STDOUT, $msg . "\n");
    $say($msg);
    $say('CLI/SYSTEM SQL identity must match a login that can open the database.');
    exit(1);
}
