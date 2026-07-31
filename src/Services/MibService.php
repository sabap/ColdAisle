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
    public const MAX_BYTES = 8_000_000; // 8 MB per file (vendor packs e.g. APC PowerNet ~3 MB)
    /** @var list<string> */
    public const ALLOWED_EXTENSIONS = ['mib', 'txt', 'my', 'mi2'];

    private static bool $loaded = false;
    private static int $loadedCount = 0;
    /** @var array<string,array{name:string,module:string}>|null numeric OID (no leading dot) => name info */
    private static ?array $oidIndex = null;
    private static int $oidIndexSize = 0;

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
            $maxMb = rtrim(rtrim(number_format(self::MAX_BYTES / 1_000_000, 1, '.', ''), '0'), '.');
            throw new RuntimeException(
                'MIB file must be between 1 byte and ' . $maxMb . ' MB'
                . ' (got ' . number_format($size / 1_000_000, 2) . ' MB).'
                . ' If upload still fails, raise PHP upload_max_filesize and post_max_size in php.ini.'
            );
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
        self::resetCaches();

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
        self::resetCaches();
    }

    private static function resetCaches(): void
    {
        self::$loaded = false;
        self::$loadedCount = 0;
        self::$oidIndex = null;
        self::$oidIndexSize = 0;
    }

    /**
     * Load all local MIB files into the Net-SNMP library for this process.
     * Also prepares the offline OID→name index used when Net-SNMP still
     * returns numeric OIDs (common on Windows).
     *
     * Safe to call repeatedly (loads once per request unless $force).
     *
     * @return int Number of MIB files successfully passed to snmp_read_mib
     */
    public static function loadAll(bool $force = false): int
    {
        if (self::$loaded && !$force) {
            return self::$loadedCount;
        }
        self::$loaded = true;
        self::$loadedCount = 0;

        try {
            $dir = self::ensureDirectory();
        } catch (Throwable $e) {
            return 0;
        }

        $dirFwd = str_replace('\\', '/', $dir);

        // Windows Net-SNMP: NEVER set MIBS=ALL in the IIS/PHP process.
        // That forces autoload of missing base MIBs (IP-MIB, SNMPv2-MIB, …) and often
        // hangs or crashes the request → IIS "Internal Server Error" on Discover.
        // The scheduled poller uses the same rule (see scripts/run_poll_snmp.cmd / poll_snmp.php).
        // Only our app-owned directory; snmp_read_mib() loads uploaded vendor files explicitly.
        @putenv('MIBS=');
        @putenv('MIBDIRS=' . $dirFwd);
        $snmpHome = App::ROOT . '/storage/snmp';
        if (is_dir($snmpHome)) {
            @putenv('SNMPCONFPATH=' . str_replace('\\', '/', $snmpHome));
        }
        if (function_exists('ini_set')) {
            @ini_set('snmp.mib_directory', $dirFwd);
        }

        if (self::snmpExtensionLoaded() && self::canReadMib()) {
            $files = self::listMibs();
            // Prefer base / RFC-ish names first so vendor IMPORTs can resolve
            usort($files, static function ($a, $b) {
                $rank = static function (string $fn): int {
                    $l = strtolower($fn);
                    if (str_contains($l, 'rfc') || str_contains($l, 'snmpv2') || str_contains($l, 'smi')
                        || str_contains($l, 'ianai') || str_contains($l, 'if-mib') || str_contains($l, 'mib-2')
                    ) {
                        return 0;
                    }
                    // Large vendor packs (PowerNet) after small base modules
                    if (str_contains($l, 'powernet') || str_contains($l, 'apc')) {
                        return 2;
                    }
                    return 1;
                };
                return $rank($a['filename']) <=> $rank($b['filename'])
                    ?: strcasecmp($a['filename'], $b['filename']);
            });
            foreach ($files as $f) {
                $path = $dir . DIRECTORY_SEPARATOR . $f['filename'];
                if (!is_readable($path)) {
                    continue;
                }
                // Cap per-file time so a bad MIB cannot freeze Discover forever
                $ok = @snmp_read_mib($path);
                if ($ok) {
                    self::$loadedCount++;
                }
            }
        }

        // Offline text index for Discover names (does not require Net-SNMP autoload)
        try {
            self::buildOidNameIndex(true);
        } catch (Throwable $e) {
            App::log('MibService OID index: ' . $e->getMessage(), 'warning');
            self::$oidIndex = [];
            self::$oidIndexSize = 0;
        }

        return self::$loadedCount;
    }

    /**
     * Number of OBJECT nodes resolved to numeric OIDs from uploaded MIBs.
     */
    public static function oidIndexSize(): int
    {
        if (self::$oidIndex === null) {
            self::buildOidNameIndex();
        }
        return self::$oidIndexSize;
    }

    /**
     * Resolve a numeric OID (with optional instance suffix) to Module::name[.inst…].
     *
     * @return array{name:string,module:string}|null
     */
    /**
     * Normalize numeric OID form used by PHP SNMP / Net-SNMP on Windows.
     * Often omits the leading iso(1): "3.6.1.4.1.318…" → "1.3.6.1.4.1.318…".
     */
    public static function normalizeNumericOid(string $numericOid): string
    {
        $oid = ltrim(trim($numericOid), '.');
        if ($oid === '') {
            return '';
        }
        // Common Windows/Net-SNMP quirk: drop leading "1."
        if (preg_match('/^3\.6\.1(?:\.|$)/', $oid)) {
            $oid = '1.' . $oid;
        }
        return $oid;
    }

    public static function resolveOidName(string $numericOid): ?array
    {
        $oid = self::normalizeNumericOid($numericOid);
        if ($oid === '' || !preg_match('/^\d+(?:\.\d+)*$/', $oid)) {
            return null;
        }
        $index = self::buildOidNameIndex();
        if (!$index) {
            return null;
        }

        $parts = explode('.', $oid);
        for ($len = count($parts); $len >= 1; $len--) {
            $prefix = implode('.', array_slice($parts, 0, $len));
            if (!isset($index[$prefix])) {
                continue;
            }
            $info = $index[$prefix];
            $suffix = array_slice($parts, $len);
            $name = $info['module'] . '::' . $info['name'];
            if ($suffix) {
                $name .= '.' . implode('.', $suffix);
            }
            return [
                'name' => $name,
                'module' => $info['module'],
            ];
        }
        return null;
    }

    /**
     * Build numeric OID → {name,module} from uploaded MIB text.
     * Does not depend on Net-SNMP translation (works on Windows IIS).
     *
     * @return array<string,array{name:string,module:string}>
     */
    public static function buildOidNameIndex(bool $force = false): array
    {
        if (self::$oidIndex !== null && !$force) {
            return self::$oidIndex;
        }

        /** @var array<string,array{parent:string,subs:list<int>,module:string}> $nodes */
        $nodes = [];
        // Well-known SMI roots (so vendor trees can resolve without base MIB files)
        $roots = [
            'iso' => '1',
            'org' => '1.3',
            'dod' => '1.3.6',
            'internet' => '1.3.6.1',
            'directory' => '1.3.6.1.1',
            'mgmt' => '1.3.6.1.2',
            'mib-2' => '1.3.6.1.2.1',
            'mib_2' => '1.3.6.1.2.1',
            'transmission' => '1.3.6.1.2.1.10',
            'experimental' => '1.3.6.1.3',
            'private' => '1.3.6.1.4',
            'enterprises' => '1.3.6.1.4.1',
            'security' => '1.3.6.1.5',
            'snmpv2' => '1.3.6.1.6',
            'snmpmodules' => '1.3.6.1.6.3',
            'ccitt' => '0',
            'joint-iso-ccitt' => '2',
        ];
        foreach ($roots as $n => $oid) {
            $nodes[strtolower($n)] = [
                'parent' => '',
                'subs' => array_map('intval', explode('.', $oid)),
                'module' => 'SNMPv2-SMI',
                'absolute' => $oid,
            ];
        }

        try {
            $dir = self::ensureDirectory();
        } catch (Throwable $e) {
            self::$oidIndex = [];
            self::$oidIndexSize = 0;
            return self::$oidIndex;
        }

        $files = self::listMibs();
        foreach ($files as $f) {
            $path = $dir . DIRECTORY_SEPARATOR . $f['filename'];
            if (!is_readable($path)) {
                continue;
            }
            $module = $f['module'] ?: (pathinfo($f['filename'], PATHINFO_FILENAME) ?: 'UNKNOWN');
            self::parseMibAssignments($path, (string)$module, $nodes);
        }

        // Resolve all named nodes to absolute OIDs
        $resolved = [];
        $resolve = null;
        $resolve = static function (string $name) use (&$resolve, &$nodes, &$resolved): ?string {
            $key = strtolower($name);
            if (array_key_exists($key, $resolved)) {
                $v = $resolved[$key];
                return $v === '' ? null : $v;
            }
            if (!isset($nodes[$key])) {
                return null;
            }
            $node = $nodes[$key];
            if (!empty($node['absolute'])) {
                $resolved[$key] = (string)$node['absolute'];
                return $resolved[$key];
            }
            // Guard cycles
            $resolved[$key] = '';
            $parent = (string)($node['parent'] ?? '');
            $subs = $node['subs'] ?? [];
            if (!is_array($subs)) {
                $subs = [];
            }
            if ($parent === '') {
                $oid = $subs ? implode('.', $subs) : '';
                $resolved[$key] = $oid;
                return $oid !== '' ? $oid : null;
            }
            $parentOid = $resolve($parent);
            if ($parentOid === null || $parentOid === '') {
                $resolved[$key] = '';
                return null;
            }
            $oid = $parentOid . ($subs ? ('.' . implode('.', $subs)) : '');
            $resolved[$key] = $oid;
            return $oid;
        };

        $index = [];
        foreach ($nodes as $key => $node) {
            // Skip pure SMI anchors only (still absolute). Vendor redefinitions of
            // names like "experimental" (APC products.4) must stay in the index.
            if (isset($roots[$key]) && !empty($node['absolute'])) {
                continue;
            }
            $oid = $resolve($key);
            if ($oid === null || $oid === '') {
                continue;
            }
            $dispName = (string)($node['label'] ?? $node['name'] ?? $key);
            $mod = (string)($node['module'] ?? 'MIB');
            $index[$oid] = [
                'name' => $dispName,
                'module' => $mod,
            ];
        }

        self::$oidIndex = $index;
        self::$oidIndexSize = count($index);
        return self::$oidIndex;
    }

    /**
     * Parse OBJECT-TYPE / OBJECT IDENTIFIER assignments from one MIB file into $nodes.
     *
     * Two-pass: high-precision OBJECT IDENTIFIER lines first, then OBJECT-TYPE etc.
     * IMPORTS clauses like "FROM SNMP-FRAMEWORK-MIB / OBJECT-TYPE FROM RFC-1212"
     * must not steal the first real "::= { enterprises … }" assignment (PowerNet).
     *
     * @param array<string,array<string,mixed>> $nodes
     */
    private static function parseMibAssignments(string $path, string $module, array &$nodes): void
    {
        $text = @file_get_contents($path);
        if (!is_string($text) || $text === '') {
            return;
        }
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        // Strip ASN.1 comments
        $text = preg_replace('/--[^\r\n]*/', '', $text) ?? $text;

        // Module name from DEFINITIONS if present
        if (preg_match('/^\s*([A-Za-z][A-Za-z0-9_-]*)\s+DEFINITIONS\s*::=\s*BEGIN/mi', $text, $mm)) {
            $module = $mm[1];
        }

        // Pass 1 — OBJECT IDENTIFIER with immediate ::= { … }
        // (no body gap; avoids IMPORTS false positives)
        if (preg_match_all(
            '/\b([A-Za-z][A-Za-z0-9_-]*)\s+OBJECT\s+IDENTIFIER\s*::=\s*\{([^}]+)\}/i',
            $text,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                self::addOidNode($nodes, $m[1], $m[2], $module);
            }
        }

        // Pass 2 — OBJECT-TYPE / OBJECT-IDENTITY / MODULE-IDENTITY / NOTIFICATION-TYPE
        if (preg_match_all(
            '/\b([A-Za-z][A-Za-z0-9_-]*)\s+'
            . '(?:OBJECT-TYPE|OBJECT-IDENTITY|MODULE-IDENTITY|NOTIFICATION-TYPE)\b'
            . '([\s\S]{0,12000}?)'
            . '::=\s*\{([^}]+)\}/i',
            $text,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $label = $m[1];
                $body = $m[2] ?? '';
                $brace = trim($m[3] ?? '');
                if ($label === '' || $brace === '') {
                    continue;
                }
                // IMPORTS residue: "SNMP-FRAMEWORK-MIB \n OBJECT-TYPE FROM RFC-1212 … ::= { enterprises 318 }"
                if (preg_match('/^\s*FROM\b/i', $body)) {
                    continue;
                }
                // Real definitions almost always carry one of these clauses
                if (!preg_match(
                    '/\b(?:SYNTAX|MAX-ACCESS|ACCESS|STATUS|DESCRIPTION|UNITS|INDEX|AUGMENTS|OBJECTS|'
                    . 'LAST-UPDATED|ORGANIZATION|CONTACT-INFO|REVISION|REFERENCE)\b/i',
                    $body
                )) {
                    continue;
                }
                self::addOidNode($nodes, $label, $brace, $module);
            }
        }
    }

    /**
     * @param array<string,array<string,mixed>> $nodes
     */
    private static function addOidNode(array &$nodes, string $label, string $brace, string $module): void
    {
        $label = trim($label);
        $brace = trim($brace);
        if ($label === '' || $brace === '') {
            return;
        }
        $parsed = self::parseOidBrace($brace);
        if ($parsed === null) {
            return;
        }
        $key = strtolower($label);
        // Never clobber core SMI anchors (vendor trees hang off enterprises)
        static $protected = [
            'iso' => true, 'org' => true, 'dod' => true, 'internet' => true,
            'directory' => true, 'mgmt' => true, 'mib-2' => true, 'mib_2' => true,
            'transmission' => true, 'private' => true, 'enterprises' => true,
            'security' => true, 'snmpv2' => true, 'snmpmodules' => true,
            'ccitt' => true, 'joint-iso-ccitt' => true,
        ];
        if (isset($protected[$key], $nodes[$key]['absolute'])) {
            return;
        }
        $nodes[$key] = [
            'label' => $label,
            'parent' => $parsed['parent'],
            'subs' => $parsed['subs'],
            'module' => $module,
            'name' => $label,
        ];
    }

    /**
     * Parse `{ parent 1 2 }`, `{ enterprises 318 }`, `{ enterprises apc(318) }`,
     * `{ iso(1) org(3) dod(6) … }`, or `{ 1 3 6 1 4 1 318 }`.
     *
     * @return array{parent:string,subs:list<int>}|null
     */
    private static function parseOidBrace(string $brace): ?array
    {
        $brace = trim($brace);
        if ($brace === '') {
            return null;
        }
        // Tokens: name, name(n), or bare integer
        if (!preg_match_all(
            '/([A-Za-z][A-Za-z0-9_-]*)\s*(?:\(\s*(\d+)\s*\))?|(\d+)/',
            $brace,
            $tm,
            PREG_SET_ORDER
        )) {
            return null;
        }

        /** @var list<array{name:?string,num:?int}> $parts */
        $parts = [];
        foreach ($tm as $t) {
            if (($t[3] ?? '') !== '') {
                $parts[] = ['name' => null, 'num' => (int)$t[3]];
            } else {
                $parts[] = [
                    'name' => $t[1],
                    'num' => (($t[2] ?? '') !== '') ? (int)$t[2] : null,
                ];
            }
        }
        if (!$parts) {
            return null;
        }

        // Absolute: every token carries a number (digit or name(n))
        $allHaveNum = true;
        $nums = [];
        foreach ($parts as $p) {
            if ($p['num'] === null) {
                $allHaveNum = false;
                break;
            }
            $nums[] = $p['num'];
        }
        if ($allHaveNum) {
            return ['parent' => '', 'subs' => $nums];
        }

        // Relative: first token is bare parent name; remaining must be numbers / name(n)
        $first = $parts[0];
        if ($first['name'] === null || $first['num'] !== null) {
            return null;
        }
        $parent = $first['name'];
        $subs = [];
        for ($i = 1, $n = count($parts); $i < $n; $i++) {
            $p = $parts[$i];
            if ($p['num'] !== null) {
                $subs[] = $p['num'];
            } else {
                // Nested bare name without number: { foo bar 1 } — unsupported
                return null;
            }
        }
        return ['parent' => $parent, 'subs' => $subs];
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
