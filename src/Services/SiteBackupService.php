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
 *
 * Optional whole-package encryption (AES-256-GCM + PBKDF2): produces a .caisle file.
 * The restore password is NOT stored anywhere — operators must retain it.
 */
declare(strict_types=1);

class SiteBackupService
{
    public const FORMAT_VERSION = 1;
    public const PACKAGE_PREFIX = 'coldaisle-site';
    /** Magic header for encrypted packages (7 bytes). */
    public const ENC_MAGIC = 'CAISLE1';
    public const ENC_PBKDF2_ITERS = 200000;

    /**
     * Create a site backup ZIP (or encrypted .caisle). Returns absolute path under storage/backups/.
     *
     * @param array{
     *   include_audit?:bool,
     *   include_readings?:bool,
     *   encrypt?:bool,
     *   password?:string
     * } $options
     */
    public static function export(array $options = []): string
    {
        if (!App::isInstalled()) {
            throw new RuntimeException('Cannot export: application is not installed.');
        }
        $includeAudit = array_key_exists('include_audit', $options) ? (bool)$options['include_audit'] : true;
        $includeReadings = array_key_exists('include_readings', $options) ? (bool)$options['include_readings'] : true;
        $encrypt = !empty($options['encrypt']);
        $password = (string)($options['password'] ?? '');
        if ($encrypt) {
            if (strlen($password) < 8) {
                throw new RuntimeException('Encryption password must be at least 8 characters.');
            }
            if (!function_exists('openssl_encrypt')) {
                throw new RuntimeException('OpenSSL is required to encrypt backups.');
            }
        }

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
                'mail' => $cfg['mail'] ?? new stdClass(),
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

            // SNMP vendor MIBs (optional inventory)
            $mibsSrc = App::ROOT . '/storage/snmp/mibs';
            $mibsDst = $staging . '/snmp_mibs';
            if (is_dir($mibsSrc)) {
                self::copyTree($mibsSrc, $mibsDst);
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
                    'encrypted' => $encrypt,
                ],
                'has_app_key' => $appKey !== '',
            ];
            file_put_contents(
                $staging . '/manifest.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

            self::zipDirectory($staging, $zipPath);
            $finalPath = $zipPath;
            if ($encrypt) {
                $encPath = $dir . DIRECTORY_SEPARATOR . $baseName . '.caisle';
                self::encryptPackageFile($zipPath, $encPath, $password);
                @unlink($zipPath);
                $finalPath = $encPath;
                App::log('Site backup created (encrypted): ' . basename($finalPath), 'info');
            } else {
                App::log('Site backup created: ' . basename($finalPath), 'info');
            }
            if (class_exists('StorageHousekeepingService')) {
                try {
                    StorageHousekeepingService::run(false);
                } catch (Throwable $e) {
                    App::log('Housekeeping after site backup: ' . $e->getMessage(), 'warning');
                }
            }
            return $finalPath;
        } finally {
            self::rrmdir($staging);
        }
    }

    /**
     * Restore a site package into the **currently running** install (same SQL DB + site folder).
     * Use when the site is already up and you want to roll back to a prior backup point.
     *
     * @param array{
     *   password?:string,
     *   create_pre_backup?:bool,
     *   restore_config_overlay?:bool
     * } $options
     * @return array{ok:bool,message:string,tables:int,rows:int,pre_backup:?string}
     */
    public static function restoreLive(string $packagePath, array $options = []): array
    {
        if (!App::isInstalled()) {
            throw new RuntimeException('Site is not installed — use setup.php → Restore from backup instead.');
        }
        if (!is_file($packagePath)) {
            throw new RuntimeException('Backup file not found.');
        }

        $createPre = array_key_exists('create_pre_backup', $options)
            ? (bool)$options['create_pre_backup']
            : true;
        $preBackup = null;
        if ($createPre) {
            try {
                $preBackup = self::export([
                    'include_audit' => true,
                    'include_readings' => true,
                    'encrypt' => false,
                ]);
                App::log('Live restore: pre-restore backup ' . basename($preBackup), 'info');
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'Could not create a pre-restore safety backup (restore aborted): ' . $e->getMessage()
                );
            }
        }

        $live = App::config();
        $db = is_array($live['database'] ?? null) ? $live['database'] : [];
        if (trim((string)($db['database'] ?? '')) === '') {
            throw new RuntimeException('Current database configuration is incomplete.');
        }

        $result = self::import($packagePath, $db, [
            'create_database' => false,
            'password' => (string)($options['password'] ?? ''),
            'preserve_live_database' => true,
            'preserve_live_base_url' => true,
            'replace_uploads' => true,
            'restore_config_overlay' => array_key_exists('restore_config_overlay', $options)
                ? (bool)$options['restore_config_overlay']
                : true,
            'timezone' => (string)($live['timezone'] ?? 'UTC'),
            'base_url' => (string)($live['base_url'] ?? ''),
            'live_config' => $live,
        ]);

        $msg = (string)($result['message'] ?? 'Restore complete.');
        if ($preBackup) {
            $msg .= ' Pre-restore safety backup: ' . basename($preBackup) . '.';
        }
        $msg .= ' Sign in again with an account from the restored backup.';
        $result['message'] = $msg;
        $result['pre_backup'] = $preBackup;

        return $result;
    }

    /**
     * List site backup packages under storage/backups/ (newest first).
     *
     * @return list<array{name:string,path:string,bytes:int,mtime:int,encrypted:bool}>
     */
    public static function listLocalPackages(): array
    {
        $dir = App::ROOT . '/storage/backups';
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $lower = strtolower($name);
            if (!str_ends_with($lower, '.zip') && !str_ends_with($lower, '.caisle')) {
                continue;
            }
            // Prefer full site packages; also allow pre-update recovery zips for power users
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path)) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'path' => $path,
                'bytes' => (int)@filesize($path),
                'mtime' => (int)@filemtime($path),
                'encrypted' => str_ends_with($lower, '.caisle') || self::isEncryptedPackage($path),
                'kind' => str_starts_with($name, self::PACKAGE_PREFIX)
                    ? 'site'
                    : (str_starts_with($name, 'backup_') ? 'pre_update' : 'other'),
            ];
        }
        usort($out, static fn($a, $b) => ($b['mtime'] <=> $a['mtime']));
        return $out;
    }

    /**
     * Restore a site package into the given SQL database and write config.php.
     *
     * @param array<string,mixed> $dbCfg host/port/database/username/password/encrypt/trust/odbc_driver
     * @param array{
     *   create_database?:bool,base_url?:string,timezone?:string,password?:string,
     *   preserve_live_database?:bool,preserve_live_base_url?:bool,replace_uploads?:bool,
     *   restore_config_overlay?:bool,live_config?:array<string,mixed>
     * } $options
     * @return array{ok:bool,message:string,tables:int,rows:int}
     */
    public static function import(string $zipPath, array $dbCfg, array $options = []): array
    {
        if (!is_file($zipPath)) {
            throw new RuntimeException('Backup file not found.');
        }

        $work = self::makeWorkDir('restore');
        $plainZip = null;

        try {
            $extractFrom = $zipPath;
            if (self::isEncryptedPackage($zipPath)) {
                $password = (string)($options['password'] ?? '');
                if ($password === '') {
                    throw new RuntimeException(
                        'This backup is encrypted. Enter the encryption password used when the backup was created.'
                    );
                }
                $plainZip = $work . DIRECTORY_SEPARATOR . 'package.zip';
                self::decryptPackageFile($zipPath, $plainZip, $password);
                $extractFrom = $plainZip;
            }
            self::extractZip($extractFrom, $work);
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
            if (!empty($options['replace_uploads']) && is_dir($uploadsDst)) {
                self::clearDirectoryContents($uploadsDst);
            }
            if (is_dir($uploadsSrc)) {
                if (!is_dir($uploadsDst)) {
                    @mkdir($uploadsDst, 0775, true);
                }
                self::copyTree($uploadsSrc, $uploadsDst);
            }

            // SNMP MIBs
            $mibsSrc = $root . '/snmp_mibs';
            $mibsDst = App::ROOT . '/storage/snmp/mibs';
            if (is_dir($mibsSrc)) {
                if (!is_dir($mibsDst)) {
                    @mkdir($mibsDst, 0775, true);
                }
                self::copyTree($mibsSrc, $mibsDst);
            }

            // Config: merge overlay + DB + app_key
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

            $liveCfg = is_array($options['live_config'] ?? null) ? $options['live_config'] : [];
            $preserveDb = !empty($options['preserve_live_database']);
            $preserveUrl = !empty($options['preserve_live_base_url']);
            $applyOverlay = array_key_exists('restore_config_overlay', $options)
                ? (bool)$options['restore_config_overlay']
                : true;

            // Live restore: keep this server's SQL credentials / base_url so the site keeps working here
            $dbHost = $preserveDb ? (string)($liveCfg['database']['host'] ?? $dbCfg['host'] ?? '') : (string)($dbCfg['host'] ?? '');
            $dbPort = $preserveDb
                ? (int)($liveCfg['database']['port'] ?? $dbCfg['port'] ?? 1433)
                : (int)($dbCfg['port'] ?? 1433);
            $dbUser = $preserveDb
                ? (string)($liveCfg['database']['username'] ?? $dbCfg['username'] ?? '')
                : (string)($dbCfg['username'] ?? '');
            $dbPass = $preserveDb
                ? (string)($liveCfg['database']['password'] ?? $dbCfg['password'] ?? '')
                : (string)($dbCfg['password'] ?? '');
            $dbEnc = $preserveDb
                ? !empty($liveCfg['database']['encrypt'] ?? $dbCfg['encrypt'] ?? false)
                : !empty($dbCfg['encrypt']);
            $dbTrust = $preserveDb
                ? !empty($liveCfg['database']['trust_server_certificate'] ?? $dbCfg['trust_server_certificate'] ?? false)
                : !empty($dbCfg['trust_server_certificate']);
            $dbOdbc = $preserveDb
                ? (string)($liveCfg['database']['odbc_driver'] ?? $dbCfg['odbc_driver'] ?? 'ODBC Driver 18 for SQL Server')
                : (string)($dbCfg['odbc_driver'] ?? 'ODBC Driver 18 for SQL Server');

            $baseUrl = $preserveUrl
                ? (string)($options['base_url'] ?? $liveCfg['base_url'] ?? '')
                : (string)($options['base_url'] ?? ($overlay['base_url'] ?? ''));

            $authDefault = [
                'local' => ['enabled' => true],
                'ldaps' => ['enabled' => false],
                'entra' => ['enabled' => false],
            ];
            $securityDefault = [
                'force_https' => false,
                'hsts' => false,
                'hsts_max_age' => 31536000,
                'cookie_secure' => 'auto',
                'cookie_samesite' => 'Lax',
                'session_idle_minutes' => 480,
                'session_absolute_minutes' => 1440,
                'bind_user_agent' => true,
            ];
            $updatesDefault = [
                'enabled' => true,
                'auto_check' => true,
                'check_interval_hours' => 24,
                'ssl_verify' => true,
            ];
            $mailDefault = [
                'enabled' => false,
                'host' => '',
                'port' => 587,
                'encryption' => 'tls',
                'auth' => true,
                'auth_mode' => 'login',
                'username' => '',
                'password' => '',
                'from_email' => '',
                'from_name' => 'ColdAisle',
                'reply_to' => '',
                'timeout' => 30,
                'verify_peer' => true,
            ];

            if ($applyOverlay) {
                $auth = is_array($overlay['auth'] ?? null) ? $overlay['auth'] : $authDefault;
                $security = is_array($overlay['security'] ?? null) ? $overlay['security'] : $securityDefault;
                $updates = is_array($overlay['updates'] ?? null) ? $overlay['updates'] : $updatesDefault;
                $mail = is_array($overlay['mail'] ?? null) ? $overlay['mail'] : $mailDefault;
                $orgName = $overlay['org_name'] ?? '';
                $tz = $options['timezone'] ?? ($overlay['timezone'] ?? 'UTC');
            } else {
                // Keep live non-DB config; still take app_key from backup so secrets decrypt
                $auth = is_array($liveCfg['auth'] ?? null) ? $liveCfg['auth'] : $authDefault;
                $security = is_array($liveCfg['security'] ?? null) ? $liveCfg['security'] : $securityDefault;
                $updates = is_array($liveCfg['updates'] ?? null) ? $liveCfg['updates'] : $updatesDefault;
                $mail = is_array($liveCfg['mail'] ?? null) ? $liveCfg['mail'] : $mailDefault;
                $orgName = $liveCfg['org_name'] ?? '';
                $tz = $options['timezone'] ?? ($liveCfg['timezone'] ?? 'UTC');
            }

            // Live restore onto another host (lab/IIS): never import transport TLS policy from the
            // package. Production force_https/HSTS will 301-loop or hang a plain-HTTP demo site.
            if ($preserveDb && is_array($liveCfg['security'] ?? null)) {
                foreach (['force_https', 'hsts', 'hsts_max_age', 'cookie_secure', 'cookie_samesite'] as $sk) {
                    if (array_key_exists($sk, $liveCfg['security'])) {
                        $security[$sk] = $liveCfg['security'][$sk];
                    }
                }
            } elseif ($preserveDb) {
                $security['force_https'] = false;
                $security['hsts'] = false;
            }

            $config = [
                'app_name' => App::APP_NAME,
                'version' => App::VERSION,
                'app_key' => $appKey,
                'timezone' => $tz,
                'base_url' => $baseUrl,
                'org_name' => $orgName,
                'database' => [
                    'host' => $dbHost,
                    'port' => $dbPort,
                    'database' => $dbName,
                    'username' => $dbUser,
                    'password' => $dbPass,
                    'encrypt' => $dbEnc,
                    'trust_server_certificate' => $dbTrust,
                    'odbc_driver' => $dbOdbc,
                ],
                'auth' => $auth,
                'security' => $security,
                'updates' => $updates,
                'mail' => $mail,
                'restored_at' => date('c'),
                'restored_from_version' => $manifest['app_version'] ?? null,
            ];
            // Preserve other live config keys (noc, debug, etc.) when restoring live
            if ($preserveDb && $liveCfg !== []) {
                foreach ($liveCfg as $k => $v) {
                    if (!array_key_exists($k, $config)) {
                        $config[$k] = $v;
                    }
                }
            }

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
            if (class_exists('SetupWizardService')) {
                try {
                    SetupWizardService::markCompleted('restore');
                } catch (Throwable $e) {
                    App::log('Setup wizard after restore: ' . $e->getMessage(), 'warning');
                }
            }
            return ['ok' => true, 'message' => $msg, 'tables' => $tablesOk, 'rows' => $rowsOk];
        } finally {
            self::rrmdir($work);
        }
    }

    /** Delete files/subdirs inside a directory without removing the directory itself. */
    private static function clearDirectoryContents(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
    }

    /**
     * Validate a package without restoring.
     * Encrypted packages return a stub unless $password is provided.
     *
     * @return array<string,mixed>
     */
    public static function inspect(string $zipPath, ?string $password = null): array
    {
        if (self::isEncryptedPackage($zipPath)) {
            if ($password === null || $password === '') {
                return [
                    'format' => 'coldaisle-site-backup',
                    'encrypted' => true,
                    'format_version' => self::FORMAT_VERSION,
                    'message' => 'Encrypted package — password required to inspect or restore.',
                ];
            }
            $work = self::makeWorkDir('inspect-enc');
            try {
                $plain = $work . DIRECTORY_SEPARATOR . 'package.zip';
                self::decryptPackageFile($zipPath, $plain, $password);
                return self::inspectPlainZip($plain);
            } finally {
                self::rrmdir($work);
            }
        }
        return self::inspectPlainZip($zipPath);
    }

    public static function isEncryptedPackage(string $path): bool
    {
        if (!is_file($path) || filesize($path) < 7 + 16 + 12 + 16 + 1) {
            return false;
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $magic = (string)fread($fh, 7);
        fclose($fh);
        return $magic === self::ENC_MAGIC;
    }

    /**
     * AES-256-GCM whole-file encryption. Output: MAGIC|salt(16)|nonce(12)|tag(16)|ciphertext
     */
    public static function encryptPackageFile(string $plainPath, string $outPath, string $password): void
    {
        $data = @file_get_contents($plainPath);
        if ($data === false || $data === '') {
            throw new RuntimeException('Cannot read backup zip for encryption.');
        }
        $salt = random_bytes(16);
        $key = hash_pbkdf2('sha256', $password, $salt, self::ENC_PBKDF2_ITERS, 32, true);
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false || strlen($tag) !== 16) {
            throw new RuntimeException('Backup encryption failed.');
        }
        $blob = self::ENC_MAGIC . $salt . $nonce . $tag . $cipher;
        if (@file_put_contents($outPath, $blob) === false) {
            throw new RuntimeException('Could not write encrypted backup file.');
        }
    }

    public static function decryptPackageFile(string $encPath, string $outZipPath, string $password): void
    {
        $raw = @file_get_contents($encPath);
        if ($raw === false || strlen($raw) < 7 + 16 + 12 + 16 + 1) {
            throw new RuntimeException('Encrypted backup file is missing or truncated.');
        }
        if (substr($raw, 0, 7) !== self::ENC_MAGIC) {
            throw new RuntimeException('Not a ColdAisle encrypted backup (.caisle).');
        }
        $salt = substr($raw, 7, 16);
        $nonce = substr($raw, 23, 12);
        $tag = substr($raw, 35, 16);
        $cipher = substr($raw, 51);
        $key = hash_pbkdf2('sha256', $password, $salt, self::ENC_PBKDF2_ITERS, 32, true);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plain === false) {
            throw new RuntimeException('Decryption failed — wrong password or corrupt file.');
        }
        if (@file_put_contents($outZipPath, $plain) === false) {
            throw new RuntimeException('Could not write decrypted backup zip.');
        }
    }

    /** @return array<string,mixed> */
    private static function inspectPlainZip(string $zipPath): array
    {
        $work = self::makeWorkDir('inspect');
        try {
            self::extractZip($zipPath, $work);
            $root = self::findPackageRoot($work);
            $manifest = json_decode((string)@file_get_contents($root . '/manifest.json'), true);
            if (!is_array($manifest)) {
                throw new RuntimeException('Invalid or missing manifest.');
            }
            $manifest['encrypted'] = false;
            return $manifest;
        } finally {
            self::rrmdir($work);
        }
    }

    /**
     * App-local temp dir (IIS often cannot use C:\\Windows\\Temp).
     * Uses storage/tmp under the app root — granted Modify by the installer.
     */
    private static function makeWorkDir(string $prefix): string
    {
        $base = App::ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tmp';
        if (!is_dir($base) && !@mkdir($base, 0775, true) && !is_dir($base)) {
            // Last resort: system temp (may fail under locked-down IIS)
            $base = rtrim(sys_get_temp_dir(), "\\/");
            if (!is_dir($base) || !is_writable($base)) {
                throw new RuntimeException(
                    'Cannot create storage/tmp for restore. Grant Modify on storage\\ to the IIS app pool identity.'
                );
            }
        }
        // Ensure writable
        $probe = $base . DIRECTORY_SEPARATOR . '.write_test_' . bin2hex(random_bytes(3));
        if (@file_put_contents($probe, 'ok') === false) {
            throw new RuntimeException(
                'storage/tmp is not writable by PHP. Grant Modify on '
                . $base . ' to IIS AppPool\\DefaultAppPool (and IUSR if impersonating).'
            );
        }
        @unlink($probe);

        $work = $base . DIRECTORY_SEPARATOR . 'coldaisle-' . preg_replace('/[^a-z0-9_-]/i', '', $prefix)
            . '-' . bin2hex(random_bytes(6));
        if (!@mkdir($work, 0775, true) && !is_dir($work)) {
            throw new RuntimeException('Cannot create work directory: ' . $work);
        }
        return $work;
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
        $pdo = Database::connection();
        $qualified = '[dbo].[' . $table . ']';

        // Only one table may have IDENTITY_INSERT ON at a time (session scope).
        // Prefer PDO::exec — pdo_odbc often does not honor SET via prepare/execute.
        if ($useIdentity) {
            try {
                $pdo->exec('SET IDENTITY_INSERT ' . $qualified . ' ON');
            } catch (Throwable $e) {
                throw new RuntimeException(
                    "Could not enable IDENTITY_INSERT for {$table}: " . $e->getMessage()
                    . ' Restore needs this to preserve primary keys / foreign keys.',
                    0,
                    $e
                );
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
                    if (!$useIdentity && $identityCol !== null && $col === $identityCol) {
                        continue;
                    }
                    $val = $row[$col];
                    if (is_bool($val)) {
                        $val = $val ? 1 : 0;
                    }
                    $data[$col] = $val;
                }
                if ($data === []) {
                    continue;
                }
                try {
                    if ($useIdentity) {
                        // ODBC-safe path: SET + INSERT as one batch with literals
                        self::insertRowIdentityBatch($table, $data);
                    } else {
                        self::insertRowPrepared($table, $data);
                    }
                } catch (Throwable $e) {
                    throw new RuntimeException(
                        "Insert into {$table} failed: " . $e->getMessage(),
                        0,
                        $e
                    );
                }
                $n++;
            }
        } finally {
            if ($useIdentity) {
                try {
                    $pdo->exec('SET IDENTITY_INSERT ' . $qualified . ' OFF');
                } catch (Throwable $e) {
                    // ignore
                }
            }
        }
        return $n;
    }

    /**
     * Insert including identity values. Uses a single T-SQL batch so ODBC applies
     * IDENTITY_INSERT for the INSERT that follows (session SET can be flaky with prepare).
     *
     * @param array<string,mixed> $data
     */
    private static function insertRowIdentityBatch(string $table, array $data): void
    {
        $normalized = [];
        foreach ($data as $k => $v) {
            $normalized[$k] = Database::normalizeValue((string)$k, $v);
        }
        $cols = array_keys($normalized);
        $colList = implode(', ', array_map(static fn ($c) => '[' . $c . ']', $cols));
        $values = [];
        foreach ($normalized as $v) {
            $values[] = self::sqlLiteral($v);
        }
        $qualified = '[dbo].[' . $table . ']';
        // Re-assert ON each row — still only one table ON at a time
        $sql = 'SET IDENTITY_INSERT ' . $qualified . ' ON; '
            . 'INSERT INTO ' . $qualified . ' (' . $colList . ') VALUES (' . implode(', ', $values) . ');';
        $pdo = Database::connection();
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // Some ODBC configs only run the first statement — try separate exec then insert
            $pdo->exec('SET IDENTITY_INSERT ' . $qualified . ' ON');
            $pdo->exec(
                'INSERT INTO ' . $qualified . ' (' . $colList . ') VALUES (' . implode(', ', $values) . ')'
            );
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function insertRowPrepared(string $table, array $data): void
    {
        $normalized = [];
        foreach ($data as $k => $v) {
            $normalized[$k] = Database::normalizeValue((string)$k, $v);
        }
        $cols = array_keys($normalized);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $colList = implode(', ', array_map(static fn ($c) => '[' . $c . ']', $cols));
        $sql = 'INSERT INTO [dbo].[' . $table . '] (' . $colList . ') VALUES (' . $placeholders . ')';
        Database::query($sql, array_values($normalized));
    }

    /** @param mixed $value */
    private static function sqlLiteral($value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return (string)$value;
        }
        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') ?: '0';
        }
        // Numeric strings that are pure integers (identity keys, etc.)
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value) && !preg_match('/[eE]/', $value)) {
            // Keep decimals as numeric literals
            return $value;
        }
        return Database::quoteString((string)$value);
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
            'cable_paths' => 50, // raceways / pathways (G-B1)
            'cables' => 51,
            'disposal_vendors' => 60,
            'disposals' => 61,
            'password_reset_tokens' => 62,
            'work_orders' => 63,
            'work_order_items' => 64,
            'asset_events' => 65,
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
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        try {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $dir,
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO
                ),
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
        } catch (Throwable $e) {
            // Best-effort cleanup — do not fail restore because Windows Temp is locked
            App::log('rrmdir ' . $dir . ': ' . $e->getMessage(), 'warning');
        }
        @rmdir($dir);
    }
}
