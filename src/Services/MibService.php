<?php
/**
 * ColdAisle — local SNMP MIB file management (upload / list / load).
 *
 * Files live under storage/snmp/mibs (app-owned, works without writing php.ini).
 * Each request that uses SNMP should call MibService::loadAll() so Net-SNMP
 * can resolve names via snmp_read_mib().
 */
declare(strict_types=1);

class MibService
{
    public const MAX_BYTES = 2_000_000; // 2 MB per file
    /** @var list<string> */
    public const ALLOWED_EXTENSIONS = ['mib', 'txt', 'my', 'mi2'];

    private static bool $loaded = false;
    private static int $loadedCount = 0;

    public static function mibDirectory(): string
    {
        return App::ROOT . '/storage/snmp/mibs';
    }

    public static function ensureDirectory(): string
    {
        $dir = self::mibDirectory();
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create MIB directory: ' . $dir);
            }
        }
        return $dir;
    }

    /**
     * Relative path for UI display.
     */
    public static function displayPath(): string
    {
        return 'storage/snmp/mibs';
    }

    public static function snmpExtensionLoaded(): bool
    {
        return extension_loaded('snmp');
    }

    public static function canReadMib(): bool
    {
        return function_exists('snmp_read_mib');
    }

    /**
     * Status for SNMP page badge / help.
     * @return array{dir:string,count:int,bytes:int,snmp:bool,read_mib:bool,php_mib_dir:?string}
     */
    public static function status(): array
    {
        $files = self::listMibs();
        $bytes = 0;
        foreach ($files as $f) {
            $bytes += (int)($f['size'] ?? 0);
        }
        $phpDir = trim((string)ini_get('snmp.mib_directory'));
        if ($phpDir === '') {
            $phpDir = null;
        }
        return [
            'dir' => self::displayPath(),
            'count' => count($files),
            'bytes' => $bytes,
            'snmp' => self::snmpExtensionLoaded(),
            'read_mib' => self::canReadMib(),
            'php_mib_dir' => $phpDir,
        ];
    }

    /**
     * @return list<array{
     *   filename:string,size:int,modified:int,modified_iso:string,
     *   module:?string,readable:bool
     * }>
     */
    public static function listMibs(): array
    {
        try {
            $dir = self::ensureDirectory();
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return [];
        }
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || $name === '.gitkeep') {
                continue;
            }
            if ($name[0] === '.') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path)) {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext !== '' && !in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                // Still list unknown extensions that look like text MIBs
                if (!preg_match('/\.(mib|txt|my|mi2)$/i', $name)) {
                    continue;
                }
            }
            $size = (int)@filesize($path);
            $mtime = (int)@filemtime($path);
            $out[] = [
                'filename' => $name,
                'size' => $size,
                'modified' => $mtime,
                'modified_iso' => $mtime > 0 ? date('Y-m-d H:i:s', $mtime) : '—',
                'module' => self::parseModuleName($path),
                'readable' => is_readable($path),
            ];
        }
        usort($out, static fn($a, $b) => strcasecmp($a['filename'], $b['filename']));
        return $out;
    }

    /**
     * Best-effort MODULE-IDENTITY / DEFINITIONS name from file header.
     */
    public static function parseModuleName(string $path): ?string
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        $chunk = (string)fread($fh, 16384);
        fclose($fh);
        if ($chunk === '') {
            return null;
        }
        // Strip UTF-8 BOM
        if (str_starts_with($chunk, "\xEF\xBB\xBF")) {
            $chunk = substr($chunk, 3);
        }
        // PowerNet-MIB DEFINITIONS ::= BEGIN
        if (preg_match('/^\s*([A-Za-z][A-Za-z0-9_-]*)\s+DEFINITIONS\s*::=\s*BEGIN/mi', $chunk, $m)) {
            return $m[1];
        }
        // MODULE-IDENTITY name sometimes appears as: xxx MODULE-IDENTITY
        if (preg_match('/^\s*([A-Za-z][A-Za-z0-9_-]*)\s+MODULE-IDENTITY\b/mi', $chunk, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Sanitize upload basename (no paths).
     */
    public static function safeFilename(string $name): string
    {
        $name = basename(str_replace(["\0", '\\', '/'], '', $name));
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'upload.mib';
        $name = trim($name, '._');
        if ($name === '') {
            $name = 'upload.mib';
        }
        // Ensure allowed extension
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            $name .= '.mib';
        }
        if (strlen($name) > 180) {
            $name = substr($name, 0, 170) . '.mib';
        }
        return $name;
    }

    /**
     * @param array<string,mixed> $file $_FILES['mib_file'] style
     * @return array{filename:string,module:?string,bytes:int,message:string}
     */
    public static function upload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrorMessage((int)($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid upload.');
        }
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new RuntimeException('MIB file must be between 1 byte and ' . (int)(self::MAX_BYTES / 1000) . ' KB.');
        }

        $orig = (string)($file['name'] ?? 'upload.mib');
        $filename = self::safeFilename($orig);
        $dir = self::ensureDirectory();
        $dest = $dir . DIRECTORY_SEPARATOR . $filename;

        // Basic content sniff: reject obvious binary / HTML
        $head = (string)@file_get_contents($tmp, false, null, 0, 512);
        if ($head !== '' && (str_contains($head, "\0") || preg_match('/<\s*html|<\s*script/i', $head))) {
            throw new RuntimeException('File does not look like a text MIB module.');
        }

        if (!@move_uploaded_file($tmp, $dest)) {
            // Fallback for non-upload contexts / ACL quirks
            if (!@copy($tmp, $dest)) {
                throw new RuntimeException('Could not save MIB to ' . self::displayPath() . ' (check IIS write permissions).');
            }
            @unlink($tmp);
        }

        // Reset load cache so next SNMP call re-reads
        self::$loaded = false;
        self::$loadedCount = 0;

        $module = self::parseModuleName($dest);
        return [
            'filename' => $filename,
            'module' => $module,
            'bytes' => (int)@filesize($dest),
            'message' => 'Uploaded ' . $filename
                . ($module ? ' (module ' . $module . ')' : '')
                . '. MIBs load automatically on the next Discover / poll in this app.',
        ];
    }

    public static function delete(string $filename): void
    {
        $filename = basename(str_replace(["\0", '\\', '/'], '', $filename));
        if ($filename === '' || $filename === '.gitkeep' || str_starts_with($filename, '.')
            || !preg_match('/^[A-Za-z0-9._-]+$/', $filename)
        ) {
            throw new RuntimeException('Invalid filename.');
        }
        $path = self::ensureDirectory() . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            throw new RuntimeException('MIB file not found: ' . $filename);
        }
        if (!@unlink($path)) {
            throw new RuntimeException('Could not delete ' . $filename . ' (check permissions).');
        }
        self::$loaded = false;
        self::$loadedCount = 0;
    }

    /**
     * Load all local MIB files into the Net-SNMP library for this process.
     * Safe to call repeatedly (loads once per request).
     *
     * @return int Number of files successfully passed to snmp_read_mib
     */
    public static function loadAll(bool $force = false): int
    {
        if (self::$loaded && !$force) {
            return self::$loadedCount;
        }
        self::$loaded = true;
        self::$loadedCount = 0;

        if (!self::snmpExtensionLoaded() || !self::canReadMib()) {
            return 0;
        }

        try {
            $dir = self::ensureDirectory();
        } catch (Throwable $e) {
            return 0;
        }

        // Prefer app MIB dir for Net-SNMP lookups when ini is empty
        $phpDir = trim((string)ini_get('snmp.mib_directory'));
        if ($phpDir === '' && function_exists('ini_set')) {
            @ini_set('snmp.mib_directory', str_replace('\\', '/', $dir));
        }

        $files = self::listMibs();
        foreach ($files as $f) {
            $path = $dir . DIRECTORY_SEPARATOR . $f['filename'];
            if (!is_readable($path)) {
                continue;
            }
            // snmp_read_mib returns true on success (PHP 8)
            $ok = @snmp_read_mib($path);
            if ($ok) {
                self::$loadedCount++;
            }
        }
        return self::$loadedCount;
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Upload exceeds maximum allowed size.',
            UPLOAD_ERR_PARTIAL => 'Upload was only partially received.',
            UPLOAD_ERR_NO_FILE => 'No file was selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'PHP temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'PHP could not write the upload to disk.',
            default => 'Upload failed (error code ' . $code . ').',
        };
    }
}
