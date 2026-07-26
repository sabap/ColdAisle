<?php
/**
 * Prune storage/backups, storage/tmp work dirs, and oversized/old logs.
 *
 * Safety:
 * - Never delete the newest pre-update backup or newest site package when keep_count >= 1
 * - Never delete files modified within the last hour (active download/export)
 * - staging folders and temp dirs cleaned more aggressively by age only
 */
declare(strict_types=1);

class StorageHousekeepingService
{
    public const CAT = 'housekeeping';

    public const KEY_AUTO = 'hk_auto_enabled';
    public const KEY_BACKUP_KEEP = 'hk_backup_keep_count';
    public const KEY_BACKUP_MAX_AGE = 'hk_backup_max_age_days';
    public const KEY_TMP_MAX_AGE = 'hk_tmp_max_age_hours';
    public const KEY_LOG_MAX_BYTES = 'hk_log_max_bytes';
    public const KEY_LOG_MAX_AGE = 'hk_log_max_age_days';
    public const KEY_LAST_RUN = 'hk_last_run_at';
    public const KEY_LAST_RESULT = 'hk_last_result';

    public const DEFAULTS = [
        self::KEY_AUTO => '1',
        self::KEY_BACKUP_KEEP => '5',
        self::KEY_BACKUP_MAX_AGE => '90',
        self::KEY_TMP_MAX_AGE => '48',
        self::KEY_LOG_MAX_BYTES => '10485760', // 10 MiB
        self::KEY_LOG_MAX_AGE => '30',
    ];

    /** @return array<string,mixed> */
    public static function settings(): array
    {
        $get = static function (string $k) {
            $d = self::DEFAULTS[$k] ?? '';
            return SettingsService::get($k, $d);
        };
        return [
            'auto_enabled' => $get(self::KEY_AUTO) === '1',
            'backup_keep_count' => max(1, min(50, (int)$get(self::KEY_BACKUP_KEEP))),
            'backup_max_age_days' => max(0, min(3650, (int)$get(self::KEY_BACKUP_MAX_AGE))),
            'tmp_max_age_hours' => max(1, min(720, (int)$get(self::KEY_TMP_MAX_AGE))),
            'log_max_bytes' => max(1024 * 100, min(500 * 1024 * 1024, (int)$get(self::KEY_LOG_MAX_BYTES))),
            'log_max_age_days' => max(0, min(3650, (int)$get(self::KEY_LOG_MAX_AGE))),
            'last_run_at' => SettingsService::get(self::KEY_LAST_RUN, null),
            'last_result' => SettingsService::get(self::KEY_LAST_RESULT, null),
        ];
    }

    /** @param array<string,mixed> $post */
    public static function saveFromPost(array $post): array
    {
        SettingsService::set(self::KEY_AUTO, !empty($post['hk_auto_enabled']) ? '1' : '0', self::CAT);
        SettingsService::set(
            self::KEY_BACKUP_KEEP,
            (string)max(1, min(50, (int)($post['hk_backup_keep_count'] ?? 5))),
            self::CAT
        );
        SettingsService::set(
            self::KEY_BACKUP_MAX_AGE,
            (string)max(0, min(3650, (int)($post['hk_backup_max_age_days'] ?? 90))),
            self::CAT
        );
        SettingsService::set(
            self::KEY_TMP_MAX_AGE,
            (string)max(1, min(720, (int)($post['hk_tmp_max_age_hours'] ?? 48))),
            self::CAT
        );
        $logMb = (float)($post['hk_log_max_mb'] ?? 10);
        $logBytes = (int)round(max(0.1, min(500, $logMb)) * 1024 * 1024);
        SettingsService::set(self::KEY_LOG_MAX_BYTES, (string)$logBytes, self::CAT);
        SettingsService::set(
            self::KEY_LOG_MAX_AGE,
            (string)max(0, min(3650, (int)($post['hk_log_max_age_days'] ?? 30))),
            self::CAT
        );
        return self::settings();
    }

    /**
     * Run prune. $force ignores auto_enabled (manual "Run now").
     *
     * @return array{
     *   ok:bool,
     *   deleted:list<string>,
     *   kept:int,
     *   freed_bytes:int,
     *   message:string,
     *   stats:array<string,int>
     * }
     */
    public static function run(bool $force = false): array
    {
        $cfg = self::settings();
        if (!$force && !$cfg['auto_enabled']) {
            return [
                'ok' => true,
                'deleted' => [],
                'kept' => 0,
                'freed_bytes' => 0,
                'message' => 'Housekeeping auto-run is disabled.',
                'stats' => [],
            ];
        }

        $deleted = [];
        $freed = 0;
        $stats = [
            'backups_deleted' => 0,
            'tmp_deleted' => 0,
            'logs_rotated' => 0,
            'logs_deleted' => 0,
        ];

        $r1 = self::pruneBackups($cfg['backup_keep_count'], $cfg['backup_max_age_days']);
        $deleted = array_merge($deleted, $r1['deleted']);
        $freed += $r1['freed_bytes'];
        $stats['backups_deleted'] = count($r1['deleted']);
        $kept = $r1['kept'];

        $r2 = self::pruneTmp($cfg['tmp_max_age_hours']);
        $deleted = array_merge($deleted, $r2['deleted']);
        $freed += $r2['freed_bytes'];
        $stats['tmp_deleted'] = count($r2['deleted']);

        $r3 = self::pruneLogs($cfg['log_max_bytes'], $cfg['log_max_age_days']);
        $deleted = array_merge($deleted, $r3['deleted']);
        $freed += $r3['freed_bytes'];
        $stats['logs_rotated'] = $r3['rotated'];
        $stats['logs_deleted'] = count($r3['deleted']);

        $msg = sprintf(
            'Deleted %d item(s), freed %s (backups=%d tmp=%d log_ops=%d).',
            count($deleted),
            self::formatBytes($freed),
            $stats['backups_deleted'],
            $stats['tmp_deleted'],
            $stats['logs_rotated'] + $stats['logs_deleted']
        );

        try {
            SettingsService::set(self::KEY_LAST_RUN, date('Y-m-d H:i:s'), self::CAT);
            SettingsService::set(self::KEY_LAST_RESULT, substr($msg, 0, 500), self::CAT);
        } catch (Throwable $e) {
            // ignore
        }

        if ($deleted) {
            App::log('Housekeeping: ' . $msg, 'info');
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'kept' => $kept,
            'freed_bytes' => $freed,
            'message' => $msg,
            'stats' => $stats,
        ];
    }

    /**
     * Run only if auto-enabled and last run was > 12 hours ago (for poll worker hook).
     */
    public static function maybeRunScheduled(): ?array
    {
        $cfg = self::settings();
        if (!$cfg['auto_enabled']) {
            return null;
        }
        $last = $cfg['last_run_at'];
        if (is_string($last) && $last !== '') {
            $ts = strtotime($last);
            if ($ts !== false && (time() - $ts) < 12 * 3600) {
                return null;
            }
        }
        return self::run(false);
    }

    /** @return list<array{name:string,path:string,bytes:int,mtime:int,kind:string}> */
    public static function listBackups(): array
    {
        $dir = self::backupsDir();
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_dir($path)) {
                if (str_ends_with($name, '_staging') || str_contains($name, '_staging')) {
                    $out[] = [
                        'name' => $name,
                        'path' => $path,
                        'bytes' => self::dirSize($path),
                        'mtime' => (int)@filemtime($path),
                        'kind' => 'staging',
                    ];
                } elseif (preg_match('/^backup_\d{8}_\d{6}/i', $name)) {
                    // Legacy folder fallback when ZipArchive was unavailable
                    $out[] = [
                        'name' => $name,
                        'path' => $path,
                        'bytes' => self::dirSize($path),
                        'mtime' => (int)@filemtime($path),
                        'kind' => 'pre_update',
                    ];
                }
                continue;
            }
            if (!is_file($path)) {
                continue;
            }
            $kind = self::classifyBackupName($name);
            if ($kind === 'other' && !preg_match('/\.(zip|ZIP)$/', $name)) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'path' => $path,
                'bytes' => (int)@filesize($path),
                'mtime' => (int)@filemtime($path),
                'kind' => $kind,
            ];
        }
        usort($out, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $out;
    }

    /** @return array{path:string,bytes:int,count:int} */
    public static function storageSummary(): array
    {
        $backups = self::listBackups();
        $bytes = 0;
        foreach ($backups as $b) {
            $bytes += $b['bytes'];
        }
        $tmpBytes = 0;
        $tmpDir = App::ROOT . '/storage/tmp';
        if (is_dir($tmpDir)) {
            $tmpBytes = self::dirSize($tmpDir);
        }
        $logBytes = 0;
        $logDir = App::ROOT . '/storage/logs';
        if (is_dir($logDir)) {
            $logBytes = self::dirSize($logDir);
        }
        return [
            'backups_bytes' => $bytes,
            'backups_count' => count(array_filter($backups, static fn($b) => $b['kind'] !== 'staging')),
            'tmp_bytes' => $tmpBytes,
            'log_bytes' => $logBytes,
            'total_bytes' => $bytes + $tmpBytes + $logBytes,
        ];
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2) . ' MB';
        }
        return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }

    // ------------------------------------------------------------------

    private static function backupsDir(): string
    {
        return App::ROOT . '/storage/backups';
    }

    private static function classifyBackupName(string $name): string
    {
        if (preg_match('/^backup_\d{8}_\d{6}/i', $name)) {
            return 'pre_update';
        }
        if (str_starts_with($name, 'coldaisle-site_') || str_contains($name, 'coldaisle-site')) {
            return 'site_export';
        }
        if (str_ends_with($name, '_staging') || str_contains($name, '_staging')) {
            return 'staging';
        }
        return 'other';
    }

    /**
     * @return array{deleted:list<string>,freed_bytes:int,kept:int}
     */
    private static function pruneBackups(int $keepCount, int $maxAgeDays): array
    {
        $dir = self::backupsDir();
        $deleted = [];
        $freed = 0;
        $kept = 0;
        if (!is_dir($dir)) {
            return ['deleted' => $deleted, 'freed_bytes' => 0, 'kept' => $kept];
        }

        $now = time();
        $minAgeProtect = 3600; // never touch files newer than 1 hour

        // Remove abandoned staging dirs older than 6 hours
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($path)) {
                continue;
            }
            if (!str_contains($name, 'staging')) {
                continue;
            }
            $mt = (int)@filemtime($path);
            if ($mt > 0 && ($now - $mt) > 6 * 3600) {
                $sz = self::dirSize($path);
                if (self::rrmdir($path)) {
                    $deleted[] = $name . '/';
                    $freed += $sz;
                }
            }
        }

        $files = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path)) {
                continue;
            }
            $kind = self::classifyBackupName($name);
            if (!in_array($kind, ['pre_update', 'site_export', 'other'], true)) {
                continue;
            }
            if (!preg_match('/\.(zip|ZIP)$/', $name) && $kind === 'other') {
                continue;
            }
            $files[] = [
                'name' => $name,
                'path' => $path,
                'mtime' => (int)@filemtime($path),
                'bytes' => (int)@filesize($path),
                'kind' => $kind,
            ];
        }

        // Group by kind; sort newest first
        $byKind = ['pre_update' => [], 'site_export' => [], 'other' => []];
        foreach ($files as $f) {
            $byKind[$f['kind']][] = $f;
        }
        foreach ($byKind as $k => &$list) {
            usort($list, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        }
        unset($list);

        foreach ($byKind as $kind => $list) {
            $kept += min($keepCount, count($list));
            foreach ($list as $i => $f) {
                $age = $now - $f['mtime'];
                if ($age < $minAgeProtect) {
                    continue; // active write protection
                }
                $overCount = $i >= $keepCount;
                $overAge = $maxAgeDays > 0 && $age > ($maxAgeDays * 86400);
                // Safety: never delete index 0 (newest) via age alone if it's the only one
                // — age can delete newest only if keep_count allows and there is a newer... 
                // Newest is index 0; overCount is false for i < keepCount.
                // Age-based delete of newest (i=0) is allowed only if keep_count is 0 — we force keep>=1
                // so newest of each kind always kept by count rule unless overAge AND i >= 1?
                // Spec: max age applies to older files; always keep newest of each kind.
                if ($i === 0) {
                    continue; // always keep newest of each kind
                }
                if ($overCount || $overAge) {
                    if (@unlink($f['path'])) {
                        $deleted[] = $f['name'];
                        $freed += $f['bytes'];
                    }
                }
            }
        }

        return ['deleted' => $deleted, 'freed_bytes' => $freed, 'kept' => $kept];
    }

    /**
     * @return array{deleted:list<string>,freed_bytes:int}
     */
    private static function pruneTmp(int $maxAgeHours): array
    {
        $deleted = [];
        $freed = 0;
        $dir = App::ROOT . '/storage/tmp';
        if (!is_dir($dir)) {
            return ['deleted' => $deleted, 'freed_bytes' => $freed];
        }
        $cutoff = time() - ($maxAgeHours * 3600);
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $name === '.gitkeep') {
                continue;
            }
            // Never delete active poll lock
            if ($name === 'snmp_poll.lock') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            $mt = (int)@filemtime($path);
            if ($mt <= 0 || $mt > $cutoff) {
                continue;
            }
            if (is_dir($path)) {
                // Prefer coldaisle-* work dirs; also clean old debug scripts after age
                $sz = self::dirSize($path);
                if (self::rrmdir($path)) {
                    $deleted[] = 'tmp/' . $name . '/';
                    $freed += $sz;
                }
            } elseif (is_file($path)) {
                $sz = (int)@filesize($path);
                if (@unlink($path)) {
                    $deleted[] = 'tmp/' . $name;
                    $freed += $sz;
                }
            }
        }
        return ['deleted' => $deleted, 'freed_bytes' => $freed];
    }

    /**
     * @return array{deleted:list<string>,freed_bytes:int,rotated:int}
     */
    private static function pruneLogs(int $maxBytes, int $maxAgeDays): array
    {
        $deleted = [];
        $freed = 0;
        $rotated = 0;
        $dir = App::ROOT . '/storage/logs';
        if (!is_dir($dir)) {
            return ['deleted' => $deleted, 'freed_bytes' => $freed, 'rotated' => 0];
        }

        $targets = [
            'app.log',
            'snmp_poll_cli.log',
            'snmp_mib_noise.log',
            'health_last_step.txt',
        ];
        $now = time();

        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path)) {
                continue;
            }

            // Age-delete rotated backups: *.log.1, *.log.old, coldaisle_* old noise
            if (preg_match('/\.(log\.\d+|log\.old|old)$/i', $name)
                || (str_starts_with($name, 'coldaisle_') && $maxAgeDays > 0)
            ) {
                $mt = (int)@filemtime($path);
                if ($maxAgeDays > 0 && $mt > 0 && ($now - $mt) > $maxAgeDays * 86400) {
                    $sz = (int)@filesize($path);
                    if (@unlink($path)) {
                        $deleted[] = 'logs/' . $name;
                        $freed += $sz;
                    }
                }
                continue;
            }

            if (!in_array($name, $targets, true) && !str_ends_with(strtolower($name), '.log')) {
                continue;
            }

            $size = (int)@filesize($path);
            if ($size > $maxBytes) {
                // Rotate: keep last ~half by copying tail, or rename to .1
                $rotatedName = $name . '.1';
                $rotatedPath = $dir . DIRECTORY_SEPARATOR . $rotatedName;
                if (is_file($rotatedPath)) {
                    $oldSz = (int)@filesize($rotatedPath);
                    if (@unlink($rotatedPath)) {
                        $freed += $oldSz;
                        $deleted[] = 'logs/' . $rotatedName . ' (replaced)';
                    }
                }
                if (@rename($path, $rotatedPath)) {
                    @file_put_contents($path, '[' . date('c') . "] log rotated (exceeded "
                        . self::formatBytes($maxBytes) . ")\n");
                    $rotated++;
                    // Truncate .1 if still huge
                    $rSz = (int)@filesize($rotatedPath);
                    if ($rSz > $maxBytes * 2) {
                        // keep last maxBytes of rotated file
                        self::truncateFileKeepTail($rotatedPath, $maxBytes);
                    }
                } else {
                    // Fallback: truncate keep tail
                    $before = $size;
                    self::truncateFileKeepTail($path, (int)($maxBytes * 0.5));
                    $after = (int)@filesize($path);
                    $freed += max(0, $before - $after);
                    $rotated++;
                }
            }
        }

        return ['deleted' => $deleted, 'freed_bytes' => $freed, 'rotated' => $rotated];
    }

    private static function truncateFileKeepTail(string $path, int $keepBytes): void
    {
        $size = (int)@filesize($path);
        if ($size <= $keepBytes) {
            return;
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return;
        }
        fseek($fh, -$keepBytes, SEEK_END);
        $tail = stream_get_contents($fh);
        fclose($fh);
        @file_put_contents($path, "--- truncated " . date('c') . " ---\n" . $tail);
    }

    private static function dirSize(string $dir): int
    {
        $total = 0;
        if (!is_dir($dir)) {
            return 0;
        }
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $total += (int)$f->getSize();
                }
            }
        } catch (Throwable $e) {
            return $total;
        }
        return $total;
    }

    private static function rrmdir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                if ($f->isDir()) {
                    @rmdir($f->getPathname());
                } else {
                    @unlink($f->getPathname());
                }
            }
            return @rmdir($dir);
        } catch (Throwable $e) {
            return false;
        }
    }
}
