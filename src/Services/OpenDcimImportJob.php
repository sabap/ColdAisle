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
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $path);
    }

    public static function patch(string $jobId, array $patch): void
    {
        $cur = self::read($jobId) ?? ['job_id' => $jobId, 'log' => []];
        foreach ($patch as $k => $v) {
            if ($k === 'log_append' && is_string($v)) {
                $cur['log'] = $cur['log'] ?? [];
                $cur['log'][] = $v;
                // keep last 400 lines
                if (count($cur['log']) > 400) {
                    $cur['log'] = array_slice($cur['log'], -400);
                }
                continue;
            }
            $cur[$k] = $v;
        }
        self::write($jobId, $cur);
    }

    /**
     * Spawn CLI worker (Windows-friendly). Falls back to same-process if spawn fails.
     */
    public static function spawnWorker(string $jobId): bool
    {
        $php = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $script = App::ROOT . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'opendcim_import_job.php';
        if (!is_file($script)) {
            return false;
        }
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($jobId);
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Detach so IIS request can return immediately
            $full = 'cmd /c start /B "" ' . $cmd . ' >NUL 2>&1';
            pclose(popen($full, 'r'));
            return true;
        }
        $full = $cmd . ' > /dev/null 2>&1 &';
        exec($full);
        return true;
    }
}
