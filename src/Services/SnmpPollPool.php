<?php
/**
 * Concurrent SNMP poll worker pool (process-based).
 *
 * PHP on Windows has no usable threads; we fan out work to short-lived CLI
 * children (php -n + snmp/pdo), capped by a concurrency setting.
 */
declare(strict_types=1);

class SnmpPollPool
{
    public const SETTING_CONCURRENCY = 'snmp_poll_concurrency';
    public const MIN_WORKERS = 1;
    public const MAX_WORKERS = 32;
    public const DEFAULT_WORKERS = 8;

    /** Soft wall-clock budget per unit worker (seconds). */
    public const UNIT_TIMEOUT_SEC = 45;

    /**
     * @return int 1 = sequential in-process (no pool)
     */
    public static function concurrency(): int
    {
        try {
            $n = (int)SettingsService::get(self::SETTING_CONCURRENCY, (string)self::DEFAULT_WORKERS);
        } catch (Throwable $e) {
            $n = self::DEFAULT_WORKERS;
        }
        return max(self::MIN_WORKERS, min(self::MAX_WORKERS, $n > 0 ? $n : self::DEFAULT_WORKERS));
    }

    public static function saveConcurrencyFromPost(array $post): int
    {
        $n = (int)($post['snmp_poll_concurrency'] ?? self::DEFAULT_WORKERS);
        $n = max(self::MIN_WORKERS, min(self::MAX_WORKERS, $n > 0 ? $n : self::DEFAULT_WORKERS));
        SettingsService::set(self::SETTING_CONCURRENCY, (string)$n, 'snmp');
        return $n;
    }

    public static function canUsePool(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $script = self::unitScriptPath();
        if (!is_file($script)) {
            return false;
        }
        $php = self::findPhpCli();
        return $php !== '';
    }

    /**
     * @param list<array{type:string,id:int,name?:string}> $jobs
     * @return array{
     *   success:int,failed:int,skipped:int,
     *   items:list<array{type:string,id:int,name:string,ok:bool,error?:string}>,
     *   workers:int,mode:string
     * }
     */
    public static function run(array $jobs, ?int $workers = null): array
    {
        $jobs = array_values(array_filter($jobs, static function ($j) {
            return is_array($j)
                && !empty($j['type'])
                && (int)($j['id'] ?? 0) > 0;
        }));
        $workers = $workers ?? self::concurrency();
        $workers = max(1, min(self::MAX_WORKERS, $workers));

        if ($jobs === []) {
            return [
                'success' => 0,
                'failed' => 0,
                'skipped' => 0,
                'items' => [],
                'workers' => $workers,
                'mode' => 'empty',
            ];
        }

        if ($workers <= 1 || !self::canUsePool()) {
            return self::runSequential($jobs);
        }

        try {
            return self::runPooled($jobs, $workers);
        } catch (Throwable $e) {
            App::log('SnmpPollPool pool failed, falling back sequential: ' . $e->getMessage(), 'warning');
            return self::runSequential($jobs);
        }
    }

    /**
     * @param list<array{type:string,id:int,name?:string}> $jobs
     * @return array{success:int,failed:int,skipped:int,items:list<array<string,mixed>>,workers:int,mode:string}
     */
    public static function runSequential(array $jobs): array
    {
        require_once __DIR__ . '/SnmpPoller.php';
        $success = 0;
        $failed = 0;
        $items = [];
        foreach ($jobs as $job) {
            $type = (string)$job['type'];
            $id = (int)$job['id'];
            $name = (string)($job['name'] ?? ($type . ' #' . $id));
            try {
                self::pollJobInProcess($type, $id);
                $success++;
                $items[] = ['type' => $type, 'id' => $id, 'name' => $name, 'ok' => true];
            } catch (Throwable $e) {
                $failed++;
                $items[] = [
                    'type' => $type,
                    'id' => $id,
                    'name' => $name,
                    'ok' => false,
                    'error' => substr($e->getMessage(), 0, 300),
                ];
                App::log("SnmpPollPool sequential {$type}#{$id}: " . $e->getMessage(), 'error');
            }
        }
        return [
            'success' => $success,
            'failed' => $failed,
            'skipped' => 0,
            'items' => $items,
            'workers' => 1,
            'mode' => 'sequential',
        ];
    }

    /**
     * @param list<array{type:string,id:int,name?:string}> $jobs
     * @return array{success:int,failed:int,skipped:int,items:list<array<string,mixed>>,workers:int,mode:string}
     */
    private static function runPooled(array $jobs, int $workers): array
    {
        $queue = $jobs;
        $active = []; // id => slot
        $success = 0;
        $failed = 0;
        $items = [];
        $slotId = 0;
        $unitTimeout = self::UNIT_TIMEOUT_SEC;

        $cmdBase = self::phpWorkerArgv();
        if ($cmdBase === null) {
            return self::runSequential($jobs);
        }

        $root = realpath(App::ROOT) ?: App::ROOT;
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        // Children inherit a light boot (skip schema ensure)
        @putenv('COLDAISLE_CLI_LIGHT=1');
        @putenv('MIBS=');
        @putenv('MIBDIRS=');

        while ($queue !== [] || $active !== []) {
            // Fill pool
            while ($queue !== [] && count($active) < $workers) {
                $job = array_shift($queue);
                $type = (string)$job['type'];
                $id = (int)$job['id'];
                $name = (string)($job['name'] ?? ($type . ' #' . $id));
                $argv = array_merge($cmdBase, [
                    '--',
                    '--type=' . $type,
                    '--id=' . $id,
                ]);
                $pipes = [];
                $opts = [];
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    $opts['bypass_shell'] = true;
                }
                $proc = @proc_open($argv, $descriptors, $pipes, $root, null, $opts);
                if (!is_resource($proc)) {
                    // Cannot spawn — finish remaining sequentially
                    array_unshift($queue, $job);
                    foreach ($active as $slot) {
                        self::reapSlot($slot, $success, $failed, $items, true);
                    }
                    $active = [];
                    $seq = self::runSequential($queue);
                    return [
                        'success' => $success + $seq['success'],
                        'failed' => $failed + $seq['failed'],
                        'skipped' => 0,
                        'items' => array_merge($items, $seq['items']),
                        'workers' => $workers,
                        'mode' => 'pool+sequential-fallback',
                    ];
                }
                if (isset($pipes[0]) && is_resource($pipes[0])) {
                    fclose($pipes[0]);
                }
                if (isset($pipes[1]) && is_resource($pipes[1])) {
                    stream_set_blocking($pipes[1], false);
                }
                if (isset($pipes[2]) && is_resource($pipes[2])) {
                    stream_set_blocking($pipes[2], false);
                }
                $slotId++;
                $active[$slotId] = [
                    'proc' => $proc,
                    'pipes' => $pipes,
                    'job' => ['type' => $type, 'id' => $id, 'name' => $name],
                    'started' => microtime(true),
                    'stdout' => '',
                    'stderr' => '',
                ];
            }

            if ($active === []) {
                break;
            }

            // Poll children
            $read = [];
            foreach ($active as $sid => $slot) {
                if (isset($slot['pipes'][1]) && is_resource($slot['pipes'][1])) {
                    $read[] = $slot['pipes'][1];
                }
                if (isset($slot['pipes'][2]) && is_resource($slot['pipes'][2])) {
                    $read[] = $slot['pipes'][2];
                }
            }
            if ($read !== []) {
                $w = null;
                $e = null;
                @stream_select($read, $w, $e, 0, 200000);
                foreach ($active as $sid => &$slot) {
                    if (isset($slot['pipes'][1]) && is_resource($slot['pipes'][1])) {
                        $chunk = stream_get_contents($slot['pipes'][1]);
                        if (is_string($chunk) && $chunk !== '') {
                            $slot['stdout'] .= $chunk;
                        }
                    }
                    if (isset($slot['pipes'][2]) && is_resource($slot['pipes'][2])) {
                        $chunk = stream_get_contents($slot['pipes'][2]);
                        if (is_string($chunk) && $chunk !== '') {
                            $slot['stderr'] .= $chunk;
                        }
                    }
                }
                unset($slot);
            } else {
                usleep(50000);
            }

            $doneIds = [];
            foreach ($active as $sid => $slot) {
                $status = proc_get_status($slot['proc']);
                $elapsed = microtime(true) - (float)$slot['started'];
                $timedOut = $elapsed >= $unitTimeout;
                if (!empty($status['running']) && !$timedOut) {
                    continue;
                }
                if ($timedOut && !empty($status['running'])) {
                    @proc_terminate($slot['proc']);
                    // Windows: ensure kill
                    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && !empty($status['pid'])) {
                        @exec('taskkill /F /PID ' . (int)$status['pid'] . ' 2>nul');
                    }
                    usleep(100000);
                }
                // Drain remaining
                if (isset($slot['pipes'][1]) && is_resource($slot['pipes'][1])) {
                    $chunk = stream_get_contents($slot['pipes'][1]);
                    if (is_string($chunk) && $chunk !== '') {
                        $slot['stdout'] .= $chunk;
                    }
                    fclose($slot['pipes'][1]);
                }
                if (isset($slot['pipes'][2]) && is_resource($slot['pipes'][2])) {
                    $chunk = stream_get_contents($slot['pipes'][2]);
                    if (is_string($chunk) && $chunk !== '') {
                        $slot['stderr'] .= $chunk;
                    }
                    fclose($slot['pipes'][2]);
                }
                $exit = @proc_close($slot['proc']);
                $parsed = self::parseWorkerOutput((string)$slot['stdout']);
                $job = $slot['job'];
                if ($timedOut && empty($parsed['ok'])) {
                    $failed++;
                    $items[] = [
                        'type' => $job['type'],
                        'id' => $job['id'],
                        'name' => $job['name'],
                        'ok' => false,
                        'error' => 'Unit poll timed out after ' . $unitTimeout . 's',
                    ];
                } elseif (!empty($parsed['ok'])) {
                    $success++;
                    $items[] = [
                        'type' => $job['type'],
                        'id' => $job['id'],
                        'name' => $job['name'],
                        'ok' => true,
                    ];
                } else {
                    $failed++;
                    $err = (string)($parsed['error'] ?? '');
                    if ($err === '' && trim((string)$slot['stderr']) !== '') {
                        $err = substr(trim((string)$slot['stderr']), 0, 300);
                    }
                    if ($err === '') {
                        $err = 'Worker exit ' . (string)$exit;
                    }
                    $items[] = [
                        'type' => $job['type'],
                        'id' => $job['id'],
                        'name' => $job['name'],
                        'ok' => false,
                        'error' => $err,
                    ];
                }
                $doneIds[] = $sid;
            }
            foreach ($doneIds as $sid) {
                unset($active[$sid]);
            }
        }

        return [
            'success' => $success,
            'failed' => $failed,
            'skipped' => 0,
            'items' => $items,
            'workers' => $workers,
            'mode' => 'pool',
        ];
    }

    /**
     * @param array{proc:resource,pipes:array,job:array,started:float,stdout:string,stderr:string} $slot
     */
    private static function reapSlot(array $slot, int &$success, int &$failed, array &$items, bool $kill): void
    {
        if ($kill && is_resource($slot['proc'])) {
            $st = @proc_get_status($slot['proc']);
            if (!empty($st['running'])) {
                @proc_terminate($slot['proc']);
            }
        }
        if (isset($slot['pipes'][1]) && is_resource($slot['pipes'][1])) {
            $slot['stdout'] .= (string)stream_get_contents($slot['pipes'][1]);
            fclose($slot['pipes'][1]);
        }
        if (isset($slot['pipes'][2]) && is_resource($slot['pipes'][2])) {
            $slot['stderr'] .= (string)stream_get_contents($slot['pipes'][2]);
            fclose($slot['pipes'][2]);
        }
        if (is_resource($slot['proc'])) {
            @proc_close($slot['proc']);
        }
        $parsed = self::parseWorkerOutput((string)$slot['stdout']);
        $job = $slot['job'];
        if (!empty($parsed['ok'])) {
            $success++;
            $items[] = ['type' => $job['type'], 'id' => $job['id'], 'name' => $job['name'], 'ok' => true];
        } else {
            $failed++;
            $items[] = [
                'type' => $job['type'],
                'id' => $job['id'],
                'name' => $job['name'],
                'ok' => false,
                'error' => (string)($parsed['error'] ?? 'worker failed'),
            ];
        }
    }

    /** @return array{ok?:bool,error?:string} */
    private static function parseWorkerOutput(string $stdout): array
    {
        $stdout = trim($stdout);
        if ($stdout === '') {
            return ['ok' => false, 'error' => 'empty worker output'];
        }
        // Last JSON line wins (ignore MIB noise before it)
        $lines = preg_split("/\r\n|\n|\r/", $stdout) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim((string)$lines[$i]);
            if ($line === '' || ($line[0] ?? '') !== '{') {
                continue;
            }
            $data = json_decode($line, true);
            if (is_array($data)) {
                return $data;
            }
        }
        return ['ok' => false, 'error' => 'unparseable worker output'];
    }

    public static function pollJobInProcess(string $type, int $id): void
    {
        require_once __DIR__ . '/SnmpPoller.php';
        switch ($type) {
            case 'pdu':
                SnmpPoller::pollPduById($id);
                return;
            case 'device':
                $dev = Database::fetchOne('SELECT * FROM devices WHERE device_id = ? AND is_active = 1', [$id]);
                if (!$dev) {
                    throw new RuntimeException('Device not found');
                }
                SnmpPoller::pollDevice($dev);
                return;
            case 'ups':
                $u = Database::fetchOne('SELECT * FROM ups_units WHERE ups_id = ? AND is_active = 1', [$id]);
                if (!$u) {
                    throw new RuntimeException('UPS not found');
                }
                SnmpPoller::pollUpsUnit($u);
                return;
            case 'cooling':
                $c = Database::fetchOne(
                    'SELECT * FROM cooling_units WHERE cooling_unit_id = ? AND is_active = 1',
                    [$id]
                );
                if (!$c) {
                    throw new RuntimeException('Cooling unit not found');
                }
                SnmpPoller::pollCoolingUnit($c);
                return;
            case 'target':
                $t = Database::fetchOne('SELECT * FROM snmp_targets WHERE target_id = ?', [$id]);
                if (!$t) {
                    throw new RuntimeException('SNMP target not found');
                }
                SnmpPoller::pollTarget($t);
                return;
            default:
                throw new RuntimeException('Unknown job type: ' . $type);
        }
    }

    private static function unitScriptPath(): string
    {
        $root = realpath(App::ROOT) ?: App::ROOT;
        return $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'poll_snmp_unit.php';
    }

    public static function findPhpCli(): string
    {
        if (class_exists('OpenDcimImportJob')) {
            return OpenDcimImportJob::findPhpCli();
        }
        $candidates = [];
        if (defined('PHP_BINARY') && PHP_BINARY !== '') {
            $bin = PHP_BINARY;
            $candidates[] = $bin;
            $dir = dirname($bin);
            $candidates[] = $dir . DIRECTORY_SEPARATOR . 'php.exe';
        }
        $candidates[] = 'C:\\PHP\\php.exe';
        $candidates[] = 'C:\\php\\php.exe';
        foreach ($candidates as $c) {
            if (is_file($c) && stripos($c, 'cgi') === false) {
                return $c;
            }
        }
        return 'php';
    }

    /**
     * Build argv for a light CLI worker (php -n + needed extensions).
     * Mirrors scripts/run_poll_snmp.cmd so SNMP does not hang on MIB paths.
     *
     * @return list<string>|null
     */
    public static function phpWorkerArgv(): ?array
    {
        $php = self::findPhpCli();
        $script = self::unitScriptPath();
        if (!is_file($script)) {
            return null;
        }
        $extDir = dirname($php) . DIRECTORY_SEPARATOR . 'ext';
        if (!is_dir($extDir)) {
            // Fallback: try known paths
            foreach (['C:\\PHP\\ext', 'C:\\php\\ext'] as $d) {
                if (is_dir($d)) {
                    $extDir = $d;
                    break;
                }
            }
        }
        $root = realpath(App::ROOT) ?: App::ROOT;
        $mibDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'snmp' . DIRECTORY_SEPARATOR . 'mibs';
        if (!is_dir($mibDir)) {
            @mkdir($mibDir, 0775, true);
        }
        $mibUnix = str_replace('\\', '/', $mibDir);

        $argv = [
            $php,
            '-n',
            '-d', 'extension_dir=' . $extDir,
        ];
        $exts = ['openssl', 'mbstring', 'curl', 'fileinfo'];
        // PDO first
        if (is_file($extDir . DIRECTORY_SEPARATOR . 'php_pdo_odbc.dll')
            || is_file($extDir . DIRECTORY_SEPARATOR . 'php_pdo_odbc.so')
        ) {
            $exts[] = 'pdo_odbc';
        } elseif (is_file($extDir . DIRECTORY_SEPARATOR . 'php_pdo_sqlsrv.dll')) {
            $exts[] = 'pdo_sqlsrv';
            if (is_file($extDir . DIRECTORY_SEPARATOR . 'php_sqlsrv.dll')) {
                $exts[] = 'sqlsrv';
            }
        }
        $exts[] = 'snmp';
        foreach ($exts as $ext) {
            $dll = $extDir . DIRECTORY_SEPARATOR . 'php_' . $ext . '.dll';
            $so = $extDir . DIRECTORY_SEPARATOR . 'php_' . $ext . '.so';
            if (is_file($dll) || is_file($so) || $ext === 'snmp') {
                $argv[] = '-d';
                $argv[] = 'extension=' . $ext;
            }
        }
        $argv[] = '-d';
        $argv[] = 'snmp.mib_directory=' . $mibUnix;
        $argv[] = '-d';
        $argv[] = 'max_execution_time=' . (string)(self::UNIT_TIMEOUT_SEC + 5);
        $argv[] = '-d';
        $argv[] = 'default_socket_timeout=3';
        $argv[] = '-d';
        $argv[] = 'output_buffering=Off';
        $argv[] = '-f';
        $argv[] = $script;

        return $argv;
    }
}
