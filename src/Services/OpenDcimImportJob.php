<?php
/**
 * File-backed job status for OpenDCIM import (UI progress polling).
 */
declare(strict_types=1);

class OpenDcimImportJob
{
    public static function dir(): string
    {
        $d = App::ROOT . '/storage/tmp/opendcim_import/jobs';
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        return $d;
    }

    public static function path(string $jobId): string
    {
        $jobId = preg_replace('/[^a-zA-Z0-9_-]/', '', $jobId) ?? '';
        if ($jobId === '') {
            throw new InvalidArgumentException('Invalid job id');
        }
        return self::dir() . '/' . $jobId . '.json';
    }

    public static function create(array $payload): string
    {
        $jobId = bin2hex(random_bytes(8));
        $data = array_merge([
            'job_id' => $jobId,
            'state' => 'queued', // queued|running|done|error
            'phase' => 'init',
            'message' => 'Queued',
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'log' => [],
            'stats' => [],
            'result' => null,
            'error' => null,
            'percent' => 0,
        ], $payload);
        self::write($jobId, $data);
        return $jobId;
    }

    public static function read(string $jobId): ?array
    {
        $path = self::path($jobId);
        if (!is_file($path)) {
            return null;
        }
        $j = json_decode((string)file_get_contents($path), true);
        return is_array($j) ? $j : null;
    }

    public static function write(string $jobId, array $data): void
    {
        $data['updated_at'] = date('c');
        $path = self::path($jobId);
        $tmp = $path . '.tmp';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            // Drop huge log if encode fails
            $data['log'] = array_slice($data['log'] ?? [], -50);
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        }
        file_put_contents($tmp, $json);
        @rename($tmp, $path);
    }

    public static function patch(string $jobId, array $patch): void
    {
        $cur = self::read($jobId) ?? ['job_id' => $jobId, 'log' => []];
        foreach ($patch as $k => $v) {
            if ($k === 'log_append' && is_string($v)) {
                $cur['log'] = $cur['log'] ?? [];
                $cur['log'][] = $v;
                if (count($cur['log']) > 400) {
                    $cur['log'] = array_slice($cur['log'], -400);
                }
                continue;
            }
            $cur[$k] = $v;
        }
        self::write($jobId, $cur);
    }

    /** Locate a CLI php.exe (not php-cgi under IIS). */
    public static function findPhpCli(): string
    {
        $candidates = [];
        if (defined('PHP_BINARY') && PHP_BINARY !== '') {
            $bin = PHP_BINARY;
            $candidates[] = $bin;
            // php-cgi.exe → php.exe in same folder
            $dir = dirname($bin);
            $candidates[] = $dir . DIRECTORY_SEPARATOR . 'php.exe';
            $candidates[] = $dir . DIRECTORY_SEPARATOR . 'php';
        }
        $candidates[] = 'C:\\PHP\\php.exe';
        $candidates[] = 'C:\\php\\php.exe';
        $candidates[] = 'php';
        foreach ($candidates as $c) {
            if ($c === 'php') {
                return 'php';
            }
            if (is_file($c) && stripos($c, 'cgi') === false) {
                return $c;
            }
        }
        return defined('PHP_BINARY') ? PHP_BINARY : 'php';
    }

    /**
     * Spawn CLI worker detached (Windows-friendly). Returns true if spawn command was issued.
     */
    public static function spawnWorker(string $jobId): bool
    {
        $php = self::findPhpCli();
        $script = App::ROOT . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'opendcim_import_job.php';
        if (!is_file($script)) {
            return false;
        }
        $logFile = self::dir() . DIRECTORY_SEPARATOR . $jobId . '.worker.log';
        $cmd = escapeshellarg($php) . ' -d max_execution_time=0 -d memory_limit=512M '
            . escapeshellarg($script) . ' ' . escapeshellarg($jobId);

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Redirect worker stdout/stderr for diagnosis; detach with start /B
            $full = 'cmd /c start /B "" ' . $cmd . ' > ' . escapeshellarg($logFile) . ' 2>&1';
            self::patch($jobId, [
                'log_append' => 'Spawning worker: ' . $php,
                'worker_log' => $logFile,
            ]);
            pclose(popen($full, 'r'));
            return true;
        }

        $full = $cmd . ' > ' . escapeshellarg($logFile) . ' 2>&1 &';
        self::patch($jobId, [
            'log_append' => 'Spawning worker: ' . $php,
            'worker_log' => $logFile,
        ]);
        exec($full);
        return true;
    }
}
