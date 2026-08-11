<?php
/**
 * ColdAisle — single-unit SNMP poll worker (pool child).
 *
 * Usage (normally spawned by SnmpPollPool):
 *   php -n ... -f poll_snmp_unit.php -- --type=pdu --id=12
 *
 * Prints one JSON line to stdout: {"ok":true,"type":"pdu","id":12}
 * or {"ok":false,"type":"pdu","id":12,"error":"..."}
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

@ini_set('max_execution_time', '50');
@ini_set('default_socket_timeout', '3');
@putenv('MIBDIRS=');
@putenv('MIBS=');
@putenv('COLDAISLE_CLI_LIGHT=1');

$root = dirname(__DIR__);
$type = '';
$id = 0;
foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if (str_starts_with($arg, '--type=')) {
        $type = strtolower(trim(substr($arg, 7)));
    } elseif (str_starts_with($arg, '--id=')) {
        $id = (int)substr($arg, 5);
    } elseif ($arg === '--type' && isset($argv[$i + 1])) {
        $type = strtolower(trim((string)$argv[$i + 1]));
    } elseif ($arg === '--id' && isset($argv[$i + 1])) {
        $id = (int)$argv[$i + 1];
    }
}

$emit = static function (array $payload) : void {
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
};

if ($type === '' || $id < 1) {
    $emit(['ok' => false, 'error' => 'usage: --type=pdu|device|ups|cooling|target --id=N']);
    exit(2);
}

try {
    require_once $root . '/src/App.php';
    App::boot();
    if (!App::isInstalled()) {
        $emit(['ok' => false, 'type' => $type, 'id' => $id, 'error' => 'not installed']);
        exit(1);
    }
    require_once $root . '/src/Services/SnmpPoller.php';
    require_once $root . '/src/Services/SnmpPollPool.php';
    SnmpPollPool::pollJobInProcess($type, $id);
    $emit(['ok' => true, 'type' => $type, 'id' => $id]);
    exit(0);
} catch (Throwable $e) {
    $emit([
        'ok' => false,
        'type' => $type,
        'id' => $id,
        'error' => substr($e->getMessage(), 0, 400),
    ]);
    exit(1);
}
