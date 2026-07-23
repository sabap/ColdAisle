<?php
/**
 * ColdAisle — GitHub release check & one-click application update.
 *
 * Flow: check API → (optional) backup → download zipball → extract over app
 * root while preserving config/config.php and storage runtime data → Schema::ensure().
 */
declare(strict_types=1);

class UpdateService
{
    public const CACHE_KEY_JSON = 'update_check_json';
    public const CACHE_KEY_AT = 'update_check_at';

    /** Public release source — not user-configurable. */
    public const GITHUB_OWNER = 'sabap';
    public const GITHUB_REPO = 'ColdAisle';

    /** Suffix for deferred replacements when the live file is locked (Windows/IIS). */
    public const PENDING_SUFFIX = '.coldaisle-new';

    /** @var list<string> pending .coldaisle-new paths created this request */
    private static array $pendingCreated = [];

    /**
     * Update behaviour. Source repo is always sabap/ColdAisle (public; no token).
     * @return array<string,mixed>
     */
    public static function config(): array
    {
        $c = App::config('updates', []);
        if (!is_array($c)) {
            $c = [];
        }
        $merged = array_merge([
            'enabled' => true,
            'auto_check' => true,
            'check_interval_hours' => 24,
            // Windows PHP often lacks a CA bundle; set false only if verify fails in your lab
            'ssl_verify' => true,
        ], $c);
        // Always pin public project — ignore any legacy config owner/repo/token
        $merged['github_owner'] = self::GITHUB_OWNER;
        $merged['github_repo'] = self::GITHUB_REPO;
        $merged['github_token'] = '';
        return $merged;
    }

    public static function githubUrl(): string
    {
        return 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO;
    }

    public static function installedVersion(): string
    {
        $path = App::ROOT . '/VERSION';
        if (is_file($path)) {
            $v = trim((string)file_get_contents($path));
            if ($v !== '' && preg_match('/^\d+\.\d+/', $v)) {
                return ltrim($v, 'vV');
            }
        }
        return ltrim((string)App::VERSION, 'vV');
    }

    /**
     * @return array{
     *   ok:bool,current:string,latest:?string,update_available:bool,
     *   release_name:?string,html_url:?string,notes:?string,
     *   published_at:?string,checked_at:string,cached:bool,error?:string
     * }
     */
    public static function checkForUpdate(bool $force = false): array
    {
        $cfg = self::config();
        $current = self::installedVersion();
        $now = date('c');

        if (empty($cfg['enabled'])) {
            return [
                'ok' => true,
                'current' => $current,
                'latest' => null,
                'update_available' => false,
                'release_name' => null,
                'html_url' => null,
                'notes' => null,
                'published_at' => null,
                'checked_at' => $now,
                'cached' => false,
                'error' => 'Updates are disabled in configuration.',
            ];
        }

        if (!$force) {
            $cached = self::cachedStatus();
            if ($cached !== null) {
                $cached['cached'] = true;
                return $cached;
            }
        }

        $owner = rawurlencode((string)$cfg['github_owner']);
        $repo = rawurlencode((string)$cfg['github_repo']);
        $token = trim((string)$cfg['github_token']);

        try {
            // Prefer formal Releases; 404 is normal when only git tags exist (no GitHub "Release" objects)
            $release = self::githubGetJson(
                "https://api.github.com/repos/{$owner}/{$repo}/releases/latest",
                $token,
                true // allow 404 → fall back to tags
            );
            $tag = null;
            $name = null;
            $html = null;
            $notes = null;
            $published = null;

            if (is_array($release) && !empty($release['tag_name']) && empty($release['message'])) {
                $tag = ltrim((string)$release['tag_name'], 'vV');
                $name = (string)($release['name'] ?? $release['tag_name']);
                $html = (string)($release['html_url'] ?? '');
                $notes = (string)($release['body'] ?? '');
                $published = (string)($release['published_at'] ?? $release['created_at'] ?? '');
            } else {
                $tags = self::githubGetJson(
                    "https://api.github.com/repos/{$owner}/{$repo}/tags?per_page=30",
                    $token,
                    false
                );
                if (!is_array($tags) || isset($tags['message'])) {
                    $msg = is_array($tags) ? (string)($tags['message'] ?? 'GitHub API error') : 'GitHub API error';
                    if (stripos($msg, 'Not Found') !== false) {
                        $msg = $token === ''
                            ? 'Repository not found or private — add a GitHub token with Contents: Read in Settings → Updates.'
                            : 'Repository not found (HTTP 404). Confirm owner/repo is sabap/ColdAisle and the token includes this repository with Contents: Read.';
                    }
                    throw new RuntimeException($msg);
                }
                // Ensure we got a list of tags, not a single error object
                if ($tags !== [] && !array_is_list($tags)) {
                    throw new RuntimeException('Unexpected tags response from GitHub.');
                }
                $best = null;
                foreach ($tags as $t) {
                    if (!is_array($t) || empty($t['name'])) {
                        continue;
                    }
                    $tv = ltrim((string)$t['name'], 'vV');
                    if (!preg_match('/^\d+\.\d+/', $tv)) {
                        continue;
                    }
                    if ($best === null || version_compare($tv, $best, '>')) {
                        $best = $tv;
                        $name = (string)$t['name'];
                    }
                }
                if ($best === null) {
                    throw new RuntimeException('No version tags found on the repository. Push a tag like v0.2.0 first.');
                }
                $tag = $best;
                $html = 'https://github.com/' . rawurlencode((string)$cfg['github_owner'])
                    . '/' . rawurlencode((string)$cfg['github_repo']) . '/releases/tag/v' . $tag;
                $notes = '';
                $published = null;
            }

            $available = version_compare($tag, $current, '>');
            $result = [
                'ok' => true,
                'current' => $current,
                'latest' => $tag,
                'update_available' => $available,
                'release_name' => $name,
                'html_url' => $html,
                'notes' => $notes,
                'published_at' => $published,
                'checked_at' => $now,
                'cached' => false,
            ];
            self::storeCache($result);
            return $result;
        } catch (Throwable $e) {
            $result = [
                'ok' => false,
                'current' => $current,
                'latest' => null,
                'update_available' => false,
                'release_name' => null,
                'html_url' => null,
                'notes' => null,
                'published_at' => null,
                'checked_at' => $now,
                'cached' => false,
                'error' => $e->getMessage(),
            ];
            // Cache failures briefly so the dashboard does not hammer GitHub
            self::storeCache($result);
            return $result;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function cachedStatus(): ?array
    {
        try {
            $raw = SettingsService::get(self::CACHE_KEY_JSON, '');
            $at = SettingsService::get(self::CACHE_KEY_AT, '');
            if ($raw === '' || $raw === null || $at === '' || $at === null) {
                return null;
            }
            $data = json_decode((string)$raw, true);
            if (!is_array($data)) {
                return null;
            }
            $cfg = self::config();
            $hours = max(1, (int)($cfg['check_interval_hours'] ?? 24));
            $ts = strtotime((string)$at);
            if ($ts === false || (time() - $ts) > ($hours * 3600)) {
                return null;
            }
            $data['checked_at'] = (string)$at;
            return $data;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Apply update to $targetVersion (semver without leading v). Backs up first.
     *
     * @return array{ok:bool,message:string,backup:?string,version:?string}
     */
    public static function applyUpdate(?string $targetVersion = null): array
    {
        @ini_set('max_execution_time', '600');
        @set_time_limit(600);

        $cfg = self::config();
        if (empty($cfg['enabled'])) {
            throw new RuntimeException('Updates are disabled.');
        }

        $status = self::checkForUpdate(true);
        if (!$status['ok']) {
            throw new RuntimeException($status['error'] ?? 'Update check failed.');
        }
        $latest = $status['latest'] ?? null;
        if ($latest === null) {
            throw new RuntimeException('No remote version found.');
        }
        $version = $targetVersion !== null && $targetVersion !== ''
            ? ltrim($targetVersion, 'vV')
            : $latest;

        $current = self::installedVersion();
        if (version_compare($version, $current, '<=')) {
            throw new RuntimeException("Already on {$current}; remote {$version} is not newer.");
        }

        $owner = (string)$cfg['github_owner'];
        $repo = (string)$cfg['github_repo'];
        $token = trim((string)$cfg['github_token']);

        $backupPath = self::createBackup();
        $tmpDir = self::makeWorkDir('upd');

        try {
            $zipFile = $tmpDir . DIRECTORY_SEPARATOR . 'release.zip';
            // GitHub zipball accepts tag with or without v
            $url = "https://api.github.com/repos/" . rawurlencode($owner) . '/' . rawurlencode($repo)
                . '/zipball/v' . rawurlencode($version);
            self::githubDownload($url, $zipFile, $token);

            $extractDir = $tmpDir . DIRECTORY_SEPARATOR . 'extract';
            self::extractZip($zipFile, $extractDir);

            $sourceRoot = self::findExtractedRoot($extractDir);
            if ($sourceRoot === null) {
                throw new RuntimeException('Could not locate application root inside the release archive.');
            }

            self::$pendingCreated = [];
            $stats = self::applyTree($sourceRoot, App::ROOT);

            // Ensure VERSION file matches applied tag
            if (!self::copyFileRobust(
                // write via temp string file
                self::writeTempString($tmpDir, $version . "\n"),
                App::ROOT . '/VERSION'
            ) && @file_put_contents(App::ROOT . '/VERSION', $version . "\n") === false) {
                throw new RuntimeException(
                    'Files may have partially updated, but VERSION could not be written. '
                    . self::aclHelpMessage()
                );
            }

            // Promote any deferred replacements now; remainder on shutdown / next request
            $pendingLeft = self::applyPendingReplacements();
            if ($pendingLeft > 0) {
                register_shutdown_function(static function (): void {
                    try {
                        self::applyPendingReplacements();
                    } catch (Throwable $e) {
                        // next request boot will retry
                    }
                });
            }

            // Schema upgrades for existing installs
            try {
                Schema::ensure();
            } catch (Throwable $e) {
                App::log('Update schema ensure: ' . $e->getMessage(), 'warning');
            }

            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            // Refresh cache to "up to date"
            $fresh = [
                'ok' => true,
                'current' => $version,
                'latest' => $version,
                'update_available' => false,
                'release_name' => 'v' . $version,
                'html_url' => $status['html_url'] ?? null,
                'notes' => null,
                'published_at' => null,
                'checked_at' => date('c'),
                'cached' => false,
            ];
            self::storeCache($fresh);

            $msg = "Updated from {$current} to {$version}. Backup: " . basename($backupPath)
                . " ({$stats['copied']} files";
            if (($stats['skipped'] ?? 0) > 0) {
                $msg .= ", {$stats['skipped']} optional skipped";
            }
            if (($stats['deferred'] ?? 0) > 0 || $pendingLeft > 0) {
                $msg .= ', some files deferred until next page load (Windows file lock)';
            }
            $msg .= ').';

            return [
                'ok' => true,
                'message' => $msg,
                'backup' => $backupPath,
                'version' => $version,
            ];
        } finally {
            self::rrmdir($tmpDir);
        }
    }

    private static function writeTempString(string $dir, string $contents): string
    {
        $path = rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . 'ver_' . bin2hex(random_bytes(3)) . '.txt';
        file_put_contents($path, $contents);
        return $path;
    }

    /**
     * Apply any *.coldaisle-new files left when a live file was locked mid-update.
     * Safe to call on every request (boot).
     * @return int number of pending files still remaining
     */
    public static function applyPendingReplacements(): int
    {
        $root = realpath(App::ROOT);
        if ($root === false || !is_dir($root)) {
            return 0;
        }
        $remaining = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                if (!$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                if (!str_ends_with($path, self::PENDING_SUFFIX)) {
                    continue;
                }
                // Skip runtime dirs
                $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
                if (str_starts_with($rel, 'storage/')) {
                    continue;
                }
                $dest = substr($path, 0, -strlen(self::PENDING_SUFFIX));
                if (self::promotePendingFile($path, $dest)) {
                    App::log('Update applied deferred file: ' . $rel, 'info');
                } else {
                    $remaining++;
                }
            }
        } catch (Throwable $e) {
            App::log('applyPendingReplacements: ' . $e->getMessage(), 'warning');
        }
        return $remaining;
    }

    private static function promotePendingFile(string $pending, string $dest): bool
    {
        if (!is_file($pending)) {
            return true;
        }
        if (is_file($dest)) {
            @chmod($dest, 0666);
            if (PHP_OS_FAMILY === 'Windows') {
                @exec('attrib -R ' . escapeshellarg($dest) . ' 2>NUL');
            }
            // Prefer overwrite without deleting first
            if (@copy($pending, $dest)) {
                @unlink($pending);
                return true;
            }
            // Try replace: only unlink if we can restore from pending
            $bak = $dest . '.coldaisle-bak';
            @unlink($bak);
            if (@rename($dest, $bak)) {
                if (@rename($pending, $dest) || @copy($pending, $dest)) {
                    @unlink($pending);
                    @unlink($bak);
                    return true;
                }
                // Restore original
                @rename($bak, $dest);
                return false;
            }
            return false;
        }
        // Dest missing (previous bad update deleted it) — restore from pending
        $parent = dirname($dest);
        if (!is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }
        if (@rename($pending, $dest) || @copy($pending, $dest)) {
            @unlink($pending);
            return true;
        }
        return false;
    }

    public static function aclHelpMessage(): string
    {
        $pool = (string)($_SERVER['APP_POOL_ID'] ?? 'DefaultAppPool');
        $root = str_replace('/', '\\', App::ROOT);
        return 'Grant Modify on the site folder to the app pool identity. Elevated PowerShell: '
            . 'icacls "' . $root . '" /grant "IIS AppPool\\' . $pool . ':(OI)(CI)M" /T'
            . ' ; icacls "' . $root . '" /grant "IUSR:(OI)(CI)M" /T'
            . ' (APP_POOL_ID=' . $pool . ')';
    }

    /** App-local work dir under storage/tmp (IIS-writable). */
    private static function makeWorkDir(string $prefix): string
    {
        $base = App::ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tmp';
        if (!is_dir($base) && !@mkdir($base, 0775, true) && !is_dir($base)) {
            $base = rtrim(sys_get_temp_dir(), "\\/");
        }
        $work = $base . DIRECTORY_SEPARATOR . 'coldaisle-' . preg_replace('/[^a-z0-9_-]/i', '', $prefix)
            . '-' . bin2hex(random_bytes(4));
        if (!@mkdir($work, 0700, true) && !is_dir($work)) {
            throw new RuntimeException(
                'Could not create temp directory for update under storage/tmp. '
                . 'Grant Modify on storage\\ to the IIS app pool.'
            );
        }
        return $work;
    }

    public static function createBackup(): string
    {
        $dir = App::ROOT . '/storage/backups';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create storage/backups for pre-update backup.');
        }
        $name = 'backup_' . date('Ymd_His') . '_v' . self::installedVersion() . '.zip';
        $path = $dir . DIRECTORY_SEPARATOR . $name;

        if (!class_exists('ZipArchive')) {
            // Fallback: copy tree to a folder (no zip)
            $folder = $dir . DIRECTORY_SEPARATOR . pathinfo($name, PATHINFO_FILENAME);
            self::copyTreeLimited(App::ROOT, $folder, true);
            return $folder;
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create backup zip.');
        }
        $root = realpath(App::ROOT);
        if ($root === false) {
            throw new RuntimeException('Invalid application root.');
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            $full = $file->getPathname();
            $rel = substr($full, strlen($root) + 1);
            $relNorm = str_replace('\\', '/', $rel);
            // Skip previous backups and huge upload blobs in backup of backup
            if (str_starts_with($relNorm, 'storage/backups/')) {
                continue;
            }
            if ($file->isDir()) {
                $zip->addEmptyDir($relNorm);
            } else {
                $zip->addFile($full, $relNorm);
            }
        }
        $zip->close();
        return $path;
    }

    /** @param array<string,mixed> $result */
    private static function storeCache(array $result): void
    {
        try {
            SettingsService::set(self::CACHE_KEY_JSON, json_encode($result, JSON_UNESCAPED_SLASHES), 'updates');
            SettingsService::set(self::CACHE_KEY_AT, date('c'), 'updates');
        } catch (Throwable $e) {
            // settings table may be unavailable
        }
    }

    /**
     * @return array<string,mixed>|list<mixed>|null null when $allowNotFound and HTTP 404
     */
    private static function githubGetJson(string $url, string $token, bool $allowNotFound = false)
    {
        $raw = self::httpRequest($url, $token, false, $allowNotFound);
        if ($raw === null) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON from GitHub API.');
        }
        return $data;
    }

    private static function githubDownload(string $url, string $dest, string $token): void
    {
        $body = self::httpRequest($url, $token, true, false);
        if ($body === null || $body === '' || strlen($body) < 100) {
            throw new RuntimeException('Downloaded release archive is empty or too small.');
        }
        if (file_put_contents($dest, $body) === false) {
            throw new RuntimeException('Could not write release archive to temp path.');
        }
    }

    /**
     * @return string|null null when $allowNotFound and HTTP 404
     */
    private static function httpRequest(string $url, string $token, bool $binary, bool $allowNotFound = false): ?string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required for updates.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed.');
        }
        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: ColdAisle-Updater',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        if ($token !== '') {
            // Support both classic (ghp_) and fine-grained (github_pat_) tokens
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $sslVerify = !empty(self::config()['ssl_verify']);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $binary ? 300 : 45,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
        ];
        if ($sslVerify) {
            $ca = self::resolveCaBundle();
            if ($ca !== null) {
                $opts[CURLOPT_CAINFO] = $ca;
            }
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            $hint = '';
            if (stripos($err, 'certificate') !== false || stripos($err, 'SSL') !== false) {
                $hint = ' Tip: place a CA bundle at config/cacert.pem, set curl.cainfo in php.ini, or uncheck “Verify TLS certificates” under Settings → Updates (lab only).';
            }
            throw new RuntimeException('HTTP request failed: ' . $err . $hint);
        }
        if ($code === 401 || $code === 403) {
            throw new RuntimeException(
                'GitHub authentication failed (HTTP ' . $code . '). '
                . 'Regenerate the PAT with Contents: Read on sabap/ColdAisle, paste it again, and Save update settings.'
            );
        }
        if ($code === 404) {
            if ($allowNotFound) {
                return null;
            }
            throw new RuntimeException(
                'GitHub resource not found (HTTP 404). Confirm owner/repo is sabap/ColdAisle '
                . 'and the token is allowed for this private repository (Contents: Read).'
            );
        }
        if ($code < 200 || $code >= 300) {
            $snippet = substr((string)$body, 0, 200);
            throw new RuntimeException("GitHub HTTP {$code}: {$snippet}");
        }
        return (string)$body;
    }

    private static function extractZip(string $zipFile, string $destDir): void
    {
        if (!is_dir($destDir) && !mkdir($destDir, 0700, true)) {
            throw new RuntimeException('Cannot create extract directory.');
        }
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipFile) !== true) {
                throw new RuntimeException('Could not open release zip.');
            }
            if (!$zip->extractTo($destDir)) {
                $zip->close();
                throw new RuntimeException('Could not extract release zip.');
            }
            $zip->close();
            return;
        }
        // Windows PowerShell fallback
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $ps = 'powershell -NoProfile -Command "Expand-Archive -LiteralPath '
                . escapeshellarg($zipFile) . ' -DestinationPath ' . escapeshellarg($destDir) . ' -Force"';
            exec($ps, $out, $code);
            if ($code !== 0) {
                throw new RuntimeException('Expand-Archive failed (install PHP zip extension for better support).');
            }
            return;
        }
        throw new RuntimeException('PHP ZipArchive extension is required to apply updates.');
    }

    private static function findExtractedRoot(string $extractDir): ?string
    {
        // GitHub zipball: single top-level folder owner-repo-sha/
        $entries = @scandir($extractDir);
        if (!is_array($entries)) {
            return null;
        }
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $path = $extractDir . DIRECTORY_SEPARATOR . $e;
            if (is_dir($path) && (is_file($path . '/src/App.php') || is_file($path . '/VERSION') || is_file($path . '/index.php'))) {
                return $path;
            }
        }
        if (is_file($extractDir . '/src/App.php')) {
            return $extractDir;
        }
        return null;
    }

    /**
     * Overlay release files onto the live site.
     * @return array{copied:int,skipped:int,deferred:int}
     */
    private static function applyTree(string $sourceRoot, string $destRoot): array
    {
        $sourceRoot = realpath($sourceRoot);
        if ($sourceRoot === false) {
            throw new RuntimeException('Invalid source root after extract.');
        }
        $copied = 0;
        $skipped = 0;
        $deferred = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            $full = $file->getPathname();
            $rel = substr($full, strlen($sourceRoot) + 1);
            $relNorm = str_replace('\\', '/', $rel);

            if (self::shouldPreserve($relNorm)) {
                continue;
            }

            $target = $destRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if ($file->isDir()) {
                if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
                    if (self::isOptionalUpdatePath($relNorm)) {
                        $skipped++;
                        continue;
                    }
                    throw new RuntimeException('Cannot create directory: ' . $relNorm);
                }
                continue;
            }
            $parent = dirname($target);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
                if (self::isOptionalUpdatePath($relNorm)) {
                    $skipped++;
                    continue;
                }
                throw new RuntimeException('Cannot create directory for: ' . $relNorm);
            }

            // Always stage the active request script as pending (cannot safely replace mid-request)
            if (self::isCurrentlyExecuting($target)) {
                if (self::stagePending($full, $target)) {
                    $deferred++;
                    $copied++;
                } elseif (self::isOptionalUpdatePath($relNorm)) {
                    $skipped++;
                } else {
                    throw new RuntimeException(
                        'Failed to stage update for active file: ' . $relNorm . '. ' . self::aclHelpMessage()
                    );
                }
                continue;
            }

            $result = self::copyFileRobust($full, $target);
            if ($result === 'ok') {
                $copied++;
            } elseif ($result === 'deferred') {
                $copied++;
                $deferred++;
            } elseif (self::isOptionalUpdatePath($relNorm)) {
                $skipped++;
                App::log("Update skipped optional file (not writable): {$relNorm}", 'warning');
            } else {
                throw new RuntimeException(
                    'Failed to copy: ' . $relNorm . '. ' . self::aclHelpMessage()
                );
            }
        }
        return ['copied' => $copied, 'skipped' => $skipped, 'deferred' => $deferred];
    }

    private static function isCurrentlyExecuting(string $dest): bool
    {
        $script = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
        if ($script === '') {
            return false;
        }
        $a = realpath($dest);
        $b = realpath($script);
        if ($a && $b) {
            return strcasecmp($a, $b) === 0;
        }
        // Dest may not exist yet
        return strcasecmp(
            str_replace('\\', '/', $dest),
            str_replace('\\', '/', $script)
        ) === 0;
    }

    /**
     * Copy with Windows-friendly handling. NEVER deletes dest before a successful write
     * (that caused 404s when settings.php was unlinked mid-update).
     *
     * @return 'ok'|'deferred'|false
     */
    private static function copyFileRobust(string $src, string $dest): string|false
    {
        if (!is_file($src) || !is_readable($src)) {
            return false;
        }
        if (is_file($dest)) {
            @chmod($dest, 0666);
            if (PHP_OS_FAMILY === 'Windows') {
                @exec('attrib -R ' . escapeshellarg($dest) . ' 2>NUL');
            }
        }

        // 1) In-place overwrite (no delete)
        if (@copy($src, $dest)) {
            return 'ok';
        }

        // 2) Stream overwrite (no delete)
        $data = @file_get_contents($src);
        if ($data !== false && @file_put_contents($dest, $data) !== false) {
            return 'ok';
        }

        // 3) Write sibling temp then atomic-ish replace without leaving dest missing
        $tmp = $dest . '.upd.' . bin2hex(random_bytes(3));
        if ($data !== false) {
            $wroteTmp = @file_put_contents($tmp, $data) !== false;
        } else {
            $wroteTmp = @copy($src, $tmp);
        }
        if ($wroteTmp) {
            // Try replace via rename of dest aside, then tmp → dest; rollback if needed
            if (!is_file($dest)) {
                if (@rename($tmp, $dest) || @copy($tmp, $dest)) {
                    @unlink($tmp);
                    return 'ok';
                }
            } else {
                $bak = $dest . '.coldaisle-bak';
                @unlink($bak);
                if (@rename($dest, $bak)) {
                    if (@rename($tmp, $dest) || @copy($tmp, $dest)) {
                        @unlink($tmp);
                        @unlink($bak);
                        return 'ok';
                    }
                    // Restore original — never leave dest missing
                    @rename($bak, $dest);
                }
            }
            @unlink($tmp);
        }

        // 4) File locked or ACL: stage for next request (keeps live file intact)
        if (self::stagePending($src, $dest)) {
            return 'deferred';
        }
        return false;
    }

    private static function stagePending(string $src, string $dest): bool
    {
        $pending = $dest . self::PENDING_SUFFIX;
        @chmod($pending, 0666);
        if (@copy($src, $pending)) {
            self::$pendingCreated[] = $pending;
            return true;
        }
        $data = @file_get_contents($src);
        if ($data !== false && @file_put_contents($pending, $data) !== false) {
            self::$pendingCreated[] = $pending;
            return true;
        }
        return false;
    }

    /** Docs / VCS noise — never abort an update if these cannot be written. */
    private static function isOptionalUpdatePath(string $relNorm): bool
    {
        $relNorm = ltrim($relNorm, '/');
        $base = basename($relNorm);
        if (in_array($base, [
            '.gitignore', '.gitattributes', '.editorconfig', '.gitkeep',
            '.DS_Store', 'Thumbs.db', 'Desktop.ini',
        ], true)) {
            return true;
        }
        if (in_array($relNorm, [
            'README.md', 'LICENSE', 'CHANGELOG.md', 'CONTRIBUTING.md',
        ], true)) {
            return true;
        }
        // Installer scripts are for greenfield servers; optional on live site
        if (str_starts_with($relNorm, 'scripts/') && str_ends_with(strtolower($base), '.ps1')) {
            return true;
        }
        if ($relNorm === 'Install-ColdAisle.ps1') {
            return true;
        }
        return false;
    }

    private static function shouldPreserve(string $relNorm): bool
    {
        $relNorm = ltrim($relNorm, '/');
        if ($relNorm === 'config/config.php') {
            return true;
        }
        if (str_starts_with($relNorm, 'storage/logs/')) {
            return true;
        }
        if (str_starts_with($relNorm, 'storage/uploads/')) {
            return true;
        }
        if (str_starts_with($relNorm, 'storage/backups/')) {
            return true;
        }
        if (str_starts_with($relNorm, 'storage/tmp/')) {
            return true;
        }
        if (str_starts_with($relNorm, '.git/')) {
            return true;
        }
        return false;
    }

    private static function copyTreeLimited(string $src, string $dst, bool $forBackup): void
    {
        $src = realpath($src);
        if ($src === false) {
            throw new RuntimeException('Invalid path for backup.');
        }
        if (!is_dir($dst) && !mkdir($dst, 0755, true)) {
            throw new RuntimeException('Cannot create backup folder.');
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            $full = $file->getPathname();
            $rel = substr($full, strlen($src) + 1);
            $relNorm = str_replace('\\', '/', $rel);
            if ($forBackup && str_starts_with($relNorm, 'storage/backups/')) {
                continue;
            }
            $target = $dst . DIRECTORY_SEPARATOR . $rel;
            if ($file->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                $parent = dirname($target);
                if (!is_dir($parent)) {
                    mkdir($parent, 0755, true);
                }
                @copy($full, $target);
            }
        }
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
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
            @rmdir($dir);
        } catch (Throwable $e) {
            // best-effort cleanup
        }
    }

    /** @return string|null Absolute path to a CA bundle if found */
    private static function resolveCaBundle(): ?string
    {
        $candidates = [
            App::ROOT . '/config/cacert.pem',
            (string)ini_get('curl.cainfo'),
            (string)ini_get('openssl.cafile'),
            'C:/PHP/extras/ssl/cacert.pem',
            'C:/php/extras/ssl/cacert.pem',
        ];
        foreach ($candidates as $p) {
            $p = trim($p);
            if ($p !== '' && is_file($p)) {
                return $p;
            }
        }
        return null;
    }
}
