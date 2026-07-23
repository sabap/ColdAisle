<?php
/**
 * ColdAisle — site backup / restore packages for migration and setup.
 *
 * Package format (ZIP):
 *   manifest.json
 *   meta/app_key.txt          — encryption key (required for sealed SNMP secrets)
 *   meta/config_overlay.json  — non-DB config (auth, security, updates, org, timezone…)
 *   data/<table>.json         — row arrays per dbo table
 *   uploads/…                 — storage/uploads tree
 *
 * Does not include: config.php DB password, storage/logs, storage/backups.
 */
declare(strict_types=1);

class SiteBackupService
{
    public const FORMAT_VERSION = 1;
    public const PACKAGE_PREFIX = 'coldaisle-site';

    /**
     * Create a site backup ZIP. Returns absolute path under storage/backups/.
     *
     * @param array{include_audit?:bool,include_readings?:bool} $options
     */
    public static function export(array $options = []): string
    {
        if (!App::isInstalled()) {
            throw new RuntimeException('Cannot export: application is not installed.');
        }
        $includeAudit = array_key_exists('include_audit', $options) ? (bool)$options['include_audit'] : true;
        $includeReadings = array_key_exists('include_readings', $options) ? (bool)$options['include_readings'] : true;

        $dir = App::ROOT . '/storage/backups';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new RuntimeException('Cannot create storage/backups.');
        }

        $stamp = date('Ymd_His');
        $baseName = self::PACKAGE_PREFIX . '_' . $stamp . '_v' . App::VERSION;
        $staging = $dir . DIRECTORY_SEPARATOR . $baseName . '_staging';
        $zipPath = $dir . DIRECTORY_SEPARATOR . $baseName . '.zip';

        self::rrmdir($staging);
        if (!@mkdir($staging, 0775, true)
            || !@mkdir($staging . '/meta', 0775, true)
            || !@mkdir($staging . '/data', 0775, true)
        ) {
            throw new RuntimeException('Cannot create backup staging directories.');
        }

        try {
            $tables = self::listTables();
            $skip = [];
            if (!$includeAudit) {
                $skip[] = 'audit_log';
            }
            if (!$includeReadings) {
                $skip = array_merge($skip, ['snmp_readings', 'pdu_readings']);
            }

            $counts = [];
            foreach ($tables as $table) {
                if (in_array($table, $skip, true)) {
                    $counts[$table] = 'skipped';
                    continue;
                }
                $rows = self::exportTable($table);
                $counts[$table] = count($rows);
                $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json === false) {
                    throw new RuntimeException("Failed to encode table {$table} as JSON.");
                }
                if (file_put_contents($staging . '/data/' . $table . '.json', $json) === false) {
                    throw new RuntimeException("Failed to write data for {$table}.");
                }
            }

            $appKey = (string)(App::config('app_key') ?? '');
            file_put_contents($staging . '/meta/app_key.txt', $appKey);

            $cfg = App::config();
            $overlay = [
                'app_name' => App::APP_NAME,
                'timezone' => $cfg['timezone'] ?? 'UTC',
                'base_url' => $cfg['base_url'] ?? '',
                'org_name' => $cfg['org_name'] ?? '',
                'auth' => $cfg['auth'] ?? new stdClass(),
                'security' => $cfg['security'] ?? new stdClass(),
                'updates' => $cfg['updates'] ?? new stdClass(),
            ];
            // Never put DB password in the overlay (new site supplies its own)
            file_put_contents(
                $staging . '/meta/config_overlay.json',
                json_encode($overlay, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

            // Uploads
            $uploadsSrc = App::ROOT . '/storage/uploads';
            $uploadsDst = $staging . '/uploads';
            if (is_dir($uploadsSrc)) {
                self::copyTree($uploadsSrc, $uploadsDst);
            } else {
                @mkdir($uploadsDst, 0775, true);
            }

            $manifest = [
                'format' => 'coldaisle-site-backup',
                'format_version' => self::FORMAT_VERSION,
                'app_version' => App::VERSION,
                'created_at' => date('c'),
                'php_version' => PHP_VERSION,
                'tables' => $counts,
                'options' => [
                    'include_audit' => $includeAudit,
                    'include_readings' => $includeReadings,
                ],
                'has_app_key' => $appKey !== '',
            ];
            file_put_contents(
                $staging . '/manifest.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

            self::zipDirectory($staging, $zipPath);
            App::log('Site backup created: ' . basename($zipPath), 'info');
            return $zipPath;
        } finally {
            self::rrmdir($staging);
        }
    }

    /**
     * Restore a site package into the given SQL database and write config.php.
     *
     * @param array<string,mixed> $dbCfg host/port/database/username/password/encrypt/trust/odbc_driver
     * @param array{create_database?:bool,base_url?:string,timezone?:string} $options
     * @return array{ok:bool,message:string,tables:int,rows:int}
     */
    public static function import(string $zipPath, array $dbCfg, array $options = []): array
    {
        if (!is_file($zipPath)) {
            throw new RuntimeException('Backup file not found.');
        }

        $work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'coldaisle-restore-' . bin2hex(random_bytes(6));
        if (!@mkdir($work, 0775, true)) {
            throw new RuntimeException('Cannot create temp restore directory.');
        }

        try {
            self::extractZip($zipPath, $work);
            $root = self::findPackageRoot($work);
            $manifestPath = $root . '/manifest.json';
            if (!is_file($manifestPath)) {
                throw new RuntimeException('Invalid backup: missing manifest.json.');
            }
            $manifest = json_decode((string)file_get_contents($manifestPath), true);
            if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'coldaisle-site-backup') {
                throw new RuntimeException('Invalid backup: not a ColdAisle site package.');
            }
            $fmt = (int)($manifest['format_version'] ?? 0);
            if ($fmt < 1 || $fmt > self::FORMAT_VERSION) {
                throw new RuntimeException('Unsupported backup format version: ' . $fmt);
            }

            $createDb = !empty($options['create_database']);
            $dbName = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($dbCfg['database'] ?? ''));
            if ($dbName === '') {
                throw new RuntimeException('Invalid database name.');
            }
            $dbCfg['database'] = $dbName;

            $serverPdo = Database::connectServer($dbCfg);
            if ($createDb) {
                $stmt = $serverPdo->prepare('SELECT database_id FROM sys.databases WHERE name = ?');
                $stmt->execute([$dbName]);
                if (!$stmt->fetchColumn()) {
                    $serverPdo->exec("CREATE DATABASE [{$dbName}]");
                }
            }

            Database::configure($dbCfg);
            $pdo = Database::connection();

            // Fresh schema
            $schema = file_get_contents(App::ROOT . '/sql/schema.sql');
            if ($schema === false) {
                throw new RuntimeException('Could not read sql/schema.sql');
            }
            Database::executeScript($pdo, $schema);
            try {
                Schema::ensure();
            } catch (Throwable $e) {
                App::log('Restore Schema::ensure: ' . $e->getMessage(), 'warning');
            }

            // Disable FKs, wipe seed rows from schema.sql, load backup, re-enable FKs
            self::setForeignKeys($pdo, false);
            foreach (array_reverse(self::listTables()) as $t) {
                try {
                    self::clearTable($t);
                } catch (Throwable $e) {
                    App::log("Restore clear {$t}: " . $e->getMessage(), 'warning');
                }
            }

            $dataDir = $root . '/data';
            $tableFiles = is_dir($dataDir)
                ? glob($dataDir . '/*.json') ?: []
                : [];
            // Prefer dependency-friendly order: known parents first, then rest alpha
            usort($tableFiles, static function (string $a, string $b): int {
                $order = self::tableImportPriority();
                $ta = basename($a, '.json');
                $tb = basename($b, '.json');
                $pa = $order[$ta] ?? 500;
                $pb = $order[$tb] ?? 500;
                if ($pa !== $pb) {
                    return $pa <=> $pb;
                }
                return strcmp($ta, $tb);
            });

            $tablesOk = 0;
            $rowsOk = 0;
            foreach ($tableFiles as $file) {
                $table = basename($file, '.json');
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                    continue;
                }
                if (!self::tableExists($table)) {
                    App::log("Restore: skip unknown table {$table}", 'warning');
                    continue;
                }
                $rows = json_decode((string)file_get_contents($file), true);
                if (!is_array($rows)) {
                    throw new RuntimeException("Corrupt data file for {$table}.");
                }
                $n = self::importTable($table, $rows);
                $rowsOk += $n;
                $tablesOk++;
            }

            self::setForeignKeys($pdo, true);

            // Uploads
            $uploadsSrc = $root . '/uploads';
            $uploadsDst = App::ROOT . '/storage/uploads';
            if (is_dir($uploadsSrc)) {
                if (!is_dir($uploadsDst)) {
                    @mkdir($uploadsDst, 0775, true);
                }
                self::copyTree($uploadsSrc, $uploadsDst);
            }

            // Config: merge overlay + new DB + preserved app_key
            $appKey = trim((string)@file_get_contents($root . '/meta/app_key.txt'));
            if ($appKey === '') {
                $appKey = Crypto::generateAppKey();
                App::log('Restore: backup had no app_key; generated a new one (sealed secrets may not decrypt).', 'warning');
            }
            $overlay = [];
            $overlayPath = $root . '/meta/config_overlay.json';
            if (is_file($overlayPath)) {
                $decoded = json_decode((string)file_get_contents($overlayPath), true);
                if (is_array($decoded)) {
                    $overlay = $decoded;
                }
            }

            $config = [
                'app_name' => App::APP_NAME,
                'version' => App::VERSION,
                'app_key' => $appKey,
                'timezone' => $options['timezone'] ?? ($overlay['timezone'] ?? 'UTC'),
                'base_url' => $options['base_url'] ?? ($overlay['base_url'] ?? ''),
                'org_name' => $overlay['org_name'] ?? '',
                'database' => [
                    'host' => $dbCfg['host'],
                    'port' => (int)($dbCfg['port'] ?? 1433),
                    'database' => $dbName,
                    'username' => $dbCfg['username'],
                    'password' => $dbCfg['password'],
                    'encrypt' => !empty($dbCfg['encrypt']),
                    'trust_server_certificate' => !empty($dbCfg['trust_server_certificate']),
                    'odbc_driver' => $dbCfg['odbc_driver'] ?? 'ODBC Driver 18 for SQL Server',
                ],
                'auth' => is_array($overlay['auth'] ?? null) ? $overlay['auth'] : [
                    'local' => ['enabled' => true],
                    'ldaps' => ['enabled' => false],
                    'entra' => ['enabled' => false],
                ],
                'security' => is_array($overlay['security'] ?? null) ? $overlay['security'] : [
                    'force_https' => false,
                    'hsts' => false,
                    'hsts_max_age' => 31536000,
                    'cookie_secure' => 'auto',
                    'cookie_samesite' => 'Lax',
                    'session_idle_minutes' => 480,
                    'session_absolute_minutes' => 1440,
                    'bind_user_agent' => true,
                ],
                'updates' => is_array($overlay['updates'] ?? null) ? $overlay['updates'] : [
                    'enabled' => true,
                    'github_owner' => 'sabap',
                    'github_repo' => 'ColdAisle',
                    'github_token' => '',
                    'auto_check' => true,
                    'check_interval_hours' => 24,
                    'ssl_verify' => true,
                ],
                'restored_at' => date('c'),
                'restored_from_version' => $manifest['app_version'] ?? null,
            ];

            $configDir = App::ROOT . '/config';
            if (!is_dir($configDir) && !@mkdir($configDir, 0775, true)) {
                throw new RuntimeException('Cannot create config directory.');
            }
            $export = var_export($config, true);
            $php = "<?php\n/** ColdAisle configuration — restored from site backup */\ndeclare(strict_types=1);\n\nreturn {$export};\n";
            if (file_put_contents($configDir . '/config.php', $php) === false) {
                throw new RuntimeException('Could not write config/config.php');
            }
            @chmod($configDir . '/config.php', 0640);

            $msg = "Restored {$tablesOk} table(s), {$rowsOk} row(s) from backup"
                . (isset($manifest['app_version']) ? " (source v{$manifest['app_version']})" : '') . '.';
            App::log($msg, 'info');
            return ['ok' => true, 'message' => $msg, 'tables' => $tablesOk, 'rows' => $rowsOk];
        } finally {
            self::rrmdir($work);
        }
    }

    /** Validate a package without restoring. */
    public static function inspect(string $zipPath): array
    {
        $work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'coldaisle-inspect-' . bin2hex(random_bytes(4));
        @mkdir($work, 0775, true);
        try {
            self::extractZip($zipPath, $work);
            $root = self::findPackageRoot($work);
            $manifest = json_decode((string)@file_get_contents($root . '/manifest.json'), true);
            if (!is_array($manifest)) {
                throw new RuntimeException('Invalid or missing manifest.');
            }
            return $manifest;
        } finally {
            self::rrmdir($work);
        }
    }

    // ─── table export / import ───────────────────────────────────────────

    /** @return list<string> */
    private static function listTables(): array
    {
        $rows = Database::fetchAll(
            "SELECT t.name AS name
             FROM sys.tables t
             INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
             WHERE s.name = 'dbo' AND t.is_ms_shipped = 0
             ORDER BY t.name"
        );
        $out = [];
        foreach ($rows as $r) {
            $n = (string)($r['name'] ?? '');
            if ($n !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $n)) {
                $out[] = $n;
            }
        }
        return $out;
    }

    private static function tableExists(string $table): bool
    {
        return (bool)Database::fetchValue(
            "SELECT 1 FROM sys.tables t
             INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
             WHERE s.name = 'dbo' AND t.name = ?",
            [$table]
        );
    }

    /** @return list<array<string,mixed>> */
    private static function exportTable(string $table): array
    {
        // Bracketed identifier — table name already validated
        return Database::fetchAll("SELECT * FROM [{$table}]");
    }

    private static function clearTable(string $table): void
    {
        try {
            Database::query("DELETE FROM [{$table}]");
        } catch (Throwable $e) {
            // retry once after FK disable path
            throw new RuntimeException("Could not clear table {$table}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private static function importTable(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $identityCol = self::identityColumn($table);
        $columns = self::tableColumns($table);
        if ($columns === []) {
            return 0;
        }

        $n = 0;
        $useIdentity = $identityCol !== null;

        if ($useIdentity) {
            try {
                Database::query("SET IDENTITY_INSERT [{$table}] ON");
            } catch (Throwable $e) {
                $useIdentity = false;
            }
        }

        try {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $data = [];
                foreach ($columns as $col) {
                    if (!array_key_exists($col, $row)) {
                        continue;
                    }
                    // Skip identity if INSERT is off
                    if (!$useIdentity && $identityCol !== null && $col === $identityCol) {
                        continue;
                    }
                    $val = $row[$col];
                    // Normalize booleans from JSON
                    if (is_bool($val)) {
                        $val = $val ? 1 : 0;
                    }
                    $data[$col] = $val;
                }
                if ($data === []) {
                    continue;
                }
                self::insertRow($table, $data);
                $n++;
            }
        } finally {
            if ($useIdentity) {
                try {
                    Database::query("SET IDENTITY_INSERT [{$table}] OFF");
                } catch (Throwable $e) {
                    // ignore
                }
            }
        }
        return $n;
    }

    /** @param array<string,mixed> $data */
    private static function insertRow(string $table, array $data): void
    {
        $normalized = [];
        foreach ($data as $k => $v) {
            $normalized[$k] = Database::normalizeValue((string)$k, $v);
        }
        $cols = array_keys($normalized);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $colList = implode(', ', array_map(static fn ($c) => '[' . $c . ']', $cols));
        $sql = "INSERT INTO [{$table}] ({$colList}) VALUES ({$placeholders})";
        Database::query($sql, array_values($normalized));
    }

    private static function identityColumn(string $table): ?string
    {
        $name = Database::fetchValue(
            "SELECT c.name
             FROM sys.columns c
             INNER JOIN sys.tables t ON t.object_id = c.object_id
             INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
             WHERE s.name = 'dbo' AND t.name = ? AND c.is_identity = 1",
            [$table]
        );
        return $name !== null && $name !== '' ? (string)$name : null;
    }

    /** @return list<string> */
    private static function tableColumns(string $table): array
    {
        $rows = Database::fetchAll(
            "SELECT c.name
             FROM sys.columns c
             INNER JOIN sys.tables t ON t.object_id = c.object_id
             INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
             WHERE s.name = 'dbo' AND t.name = ?
             ORDER BY c.column_id",
            [$table]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = (string)$r['name'];
        }
        return $out;
    }

    private static function setForeignKeys(PDO $pdo, bool $enable): void
    {
        $tables = self::listTables();
        foreach ($tables as $t) {
            try {
                if ($enable) {
                    $pdo->exec("ALTER TABLE [{$t}] WITH CHECK CHECK CONSTRAINT ALL");
                } else {
                    $pdo->exec("ALTER TABLE [{$t}] NOCHECK CONSTRAINT ALL");
                }
            } catch (Throwable $e) {
                // some tables may have no constraints
            }
        }
    }

    /** @return array<string,int> lower number = earlier */
    private static function tableImportPriority(): array
    {
        return [
            'schema_version' => 1,
            'settings' => 2,
            'roles' => 3,
            'departments' => 4,
            'users' => 5,
            'contacts' => 6,
            'sites' => 10,
            'datacenters' => 11,
            'rooms' => 12,
            'cabinet_rows' => 13,
            'cabinets' => 14,
            'manufacturers' => 15,
            'device_templates' => 16,
            'devices' => 20,
            'device_ports' => 21,
            'device_notes' => 22,
            'device_children' => 23,
            'power_zones' => 30,
            'power_panels' => 31,
            'power_circuits' => 32,
            'pdus' => 33,
            'pdu_outlets' => 34,
            'pdu_breakers' => 35,
            'device_power_supplies' => 36,
            'snmp_v3_profiles' => 40,
            'snmp_site_oid_templates' => 41,
            'snmp_targets' => 42,
            'cable_paths' => 50,
            'cables' => 51,
            'disposal_vendors' => 60,
            'disposals' => 61,
            'notifications' => 70,
            'cabinet_audits' => 80,
            'audit_jobs' => 81,
            'audit_items' => 82,
            'report_definitions' => 90,
            'role_group_maps' => 91,
            'department_group_maps' => 92,
            'rack_requests' => 93,
            'auth_sessions' => 100,
            'audit_log' => 110,
            'snmp_readings' => 120,
            'pdu_readings' => 121,
        ];
    }

    // ─── zip / filesystem ────────────────────────────────────────────────

    private static function zipDirectory(string $sourceDir, string $zipPath): void
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create backup zip (ZipArchive).');
            }
            $sourceDir = rtrim(str_replace('\\', '/', $sourceDir), '/');
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                $full = str_replace('\\', '/', $file->getPathname());
                $rel = substr($full, strlen($sourceDir) + 1);
                if ($file->isDir()) {
                    $zip->addEmptyDir($rel);
                } else {
                    $zip->addFile($file->getPathname(), $rel);
                }
            }
            $zip->close();
            if (!is_file($zipPath) || filesize($zipPath) < 50) {
                throw new RuntimeException('Backup zip is empty or missing.');
            }
            return;
        }

        // Windows PowerShell fallback
        if (PHP_OS_FAMILY === 'Windows') {
            $src = $sourceDir;
            $dst = $zipPath;
            if (is_file($dst)) {
                @unlink($dst);
            }
            $cmd = 'powershell.exe -NoProfile -Command '
                . escapeshellarg(
                    'Compress-Archive -Path ' . escapeshellarg($src . '\\*')
                    . ' -DestinationPath ' . escapeshellarg($dst) . ' -Force'
                );
            exec($cmd, $out, $code);
            if ($code !== 0 || !is_file($zipPath)) {
                throw new RuntimeException(
                    'Could not create zip (install PHP zip extension, or ensure PowerShell Compress-Archive works).'
                );
            }
            return;
        }

        throw new RuntimeException('PHP zip extension is required to create site backups on this platform.');
    }

    private static function extractZip(string $zipFile, string $destDir): void
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipFile) !== true) {
                throw new RuntimeException('Could not open backup zip.');
            }
            if (!$zip->extractTo($destDir)) {
                $zip->close();
                throw new RuntimeException('Could not extract backup zip.');
            }
            $zip->close();
            return;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'powershell.exe -NoProfile -Command '
                . escapeshellarg(
                    'Expand-Archive -LiteralPath ' . escapeshellarg($zipFile)
                    . ' -DestinationPath ' . escapeshellarg($destDir) . ' -Force'
                );
            exec($cmd, $out, $code);
            if ($code !== 0) {
                throw new RuntimeException('Expand-Archive failed (install PHP zip extension for better support).');
            }
            return;
        }
        throw new RuntimeException('PHP zip extension is required to restore site backups.');
    }

    private static function findPackageRoot(string $extractDir): string
    {
        if (is_file($extractDir . '/manifest.json')) {
            return $extractDir;
        }
        $dirs = glob($extractDir . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($dirs as $d) {
            if (is_file($d . '/manifest.json')) {
                return $d;
            }
        }
        // Some Compress-Archive layouts put files at top level already checked
        throw new RuntimeException('Could not find package root (manifest.json) in archive.');
    }

    private static function copyTree(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            @mkdir($dst, 0775, true);
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $srcNorm = rtrim(str_replace('\\', '/', $src), '/');
        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            $full = str_replace('\\', '/', $item->getPathname());
            $rel = substr($full, strlen($srcNorm) + 1);
            $target = $dst . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    @mkdir($target, 0775, true);
                }
            } else {
                $parent = dirname($target);
                if (!is_dir($parent)) {
                    @mkdir($parent, 0775, true);
                }
                @copy($item->getPathname(), $target);
            }
        }
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
