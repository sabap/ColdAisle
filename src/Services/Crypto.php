<?php
/**
 * ColdAisle — application-level encryption for secrets at rest (DB fields).
 *
 * Format: enc:v1:<base64(nonce12 || tag16 || ciphertext)>
 * Cipher: AES-256-GCM. Key: config app_key (32 bytes, base64-encoded in config.php).
 */
declare(strict_types=1);

class Crypto
{
    public const PREFIX = 'enc:v1:';

    /** @var string|null raw 32-byte key */
    private static ?string $key = null;
    private static bool $keyResolved = false;

    public static function isAvailable(): bool
    {
        return self::key() !== null && function_exists('openssl_encrypt');
    }

    public static function isEncrypted(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    /**
     * Encrypt a secret for DB storage. Empty/null pass through as null.
     * Already-encrypted values are returned unchanged.
     */
    public static function encrypt(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return null;
        }
        if (self::isEncrypted($plaintext)) {
            return $plaintext;
        }
        $key = self::key();
        if ($key === null) {
            // No key yet — store plaintext (migration will seal later when key exists)
            return $plaintext;
        }
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16
        );
        if ($cipher === false || strlen($tag) !== 16) {
            throw new RuntimeException('Encryption failed.');
        }
        return self::PREFIX . base64_encode($nonce . $tag . $cipher);
    }

    /**
     * Decrypt a sealed value. Plaintext legacy values returned as-is.
     * Returns null for empty input.
     */
    public static function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        if (!self::isEncrypted($stored)) {
            return $stored;
        }
        $key = self::key();
        if ($key === null) {
            throw new RuntimeException('Cannot decrypt secret: app_key is not configured.');
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 12 + 16 + 1) {
            throw new RuntimeException('Invalid encrypted secret payload.');
        }
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );
        if ($plain === false) {
            throw new RuntimeException('Decryption failed (wrong app_key or corrupt data).');
        }
        return $plain;
    }

    /**
     * Decrypt if sealed; never throw for UI — returns null on failure.
     */
    public static function decryptQuiet(?string $stored): ?string
    {
        try {
            return self::decrypt($stored);
        } catch (Throwable $e) {
            App::log('Crypto decrypt: ' . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Seal listed keys on a row before DB write.
     * @param array<string,mixed> $row
     * @param list<string> $keys
     * @return array<string,mixed>
     */
    public static function sealFields(array $row, array $keys): array
    {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $row)) {
                continue;
            }
            $v = $row[$k];
            if ($v === null || $v === '') {
                $row[$k] = $v === '' ? null : null;
                continue;
            }
            if (!is_string($v) && !is_numeric($v)) {
                continue;
            }
            $row[$k] = self::encrypt((string)$v);
        }
        return $row;
    }

    /**
     * Generate a new app_key (base64 of 32 random bytes).
     */
    public static function generateAppKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    /**
     * Ensure config has app_key; generate and persist if missing and config is writable.
     */
    public static function ensureAppKey(): bool
    {
        $existing = App::config('app_key');
        if (is_string($existing) && $existing !== '') {
            return true;
        }
        $path = App::configPath();
        if (!is_file($path) || !is_writable($path)) {
            App::log('Crypto: app_key missing and config.php not writable', 'warning');
            return false;
        }
        $cfg = require $path;
        if (!is_array($cfg)) {
            return false;
        }
        if (!empty($cfg['app_key'])) {
            return true;
        }
        $cfg['app_key'] = self::generateAppKey();
        $export = var_export($cfg, true);
        $php = "<?php\n/** ColdAisle configuration — app_key auto-generated for secret encryption */\ndeclare(strict_types=1);\n\nreturn {$export};\n";
        if (file_put_contents($path, $php) === false) {
            App::log('Crypto: failed to write app_key to config.php', 'error');
            return false;
        }
        // Reload App config if possible
        self::$key = null;
        self::$keyResolved = false;
        App::reloadConfig();
        App::log('Crypto: generated and saved new app_key', 'info');
        return true;
    }

    /**
     * One-time: encrypt plaintext secrets already in the database.
     * @return array{sealed:int,skipped:int,errors:int}
     */
    public static function migratePlaintextSecrets(): array
    {
        $stats = ['sealed' => 0, 'skipped' => 0, 'errors' => 0];
        if (!self::isAvailable()) {
            return $stats;
        }

        $jobs = [
            ['snmp_v3_profiles', 'profile_id', ['auth_passphrase', 'priv_passphrase']],
            ['snmp_targets', 'target_id', ['auth_passphrase', 'priv_passphrase']],
            ['devices', 'device_id', ['snmp_community', 'snmp_v3_auth_pass', 'snmp_v3_priv_pass']],
            ['pdus', 'pdu_id', ['snmp_community', 'snmp_auth_passphrase', 'snmp_priv_passphrase']],
        ];

        foreach ($jobs as [$table, $pk, $cols]) {
            try {
                $exists = Database::fetchValue(
                    "SELECT 1 FROM sys.tables WHERE name = ? AND SCHEMA_NAME(schema_id) = 'dbo'",
                    [$table]
                );
                if (!$exists) {
                    continue;
                }
                // Only select columns that exist
                $present = [];
                foreach ($cols as $c) {
                    $colOk = Database::fetchValue(
                        "SELECT 1 FROM sys.columns c
                         INNER JOIN sys.tables t ON t.object_id = c.object_id
                         WHERE t.name = ? AND c.name = ?",
                        [$table, $c]
                    );
                    if ($colOk) {
                        $present[] = $c;
                    }
                }
                if (!$present) {
                    continue;
                }
                $select = implode(', ', array_merge([$pk], $present));
                $rows = Database::fetchAll("SELECT {$select} FROM [{$table}]");
                foreach ($rows as $row) {
                    $updates = [];
                    foreach ($present as $c) {
                        $v = $row[$c] ?? null;
                        if ($v === null || $v === '' || self::isEncrypted((string)$v)) {
                            $stats['skipped']++;
                            continue;
                        }
                        try {
                            $updates[$c] = self::encrypt((string)$v);
                            $stats['sealed']++;
                        } catch (Throwable $e) {
                            $stats['errors']++;
                            App::log("Crypto migrate {$table}.{$c}: " . $e->getMessage(), 'error');
                        }
                    }
                    if ($updates) {
                        Database::update($table, $updates, "{$pk} = :id", [':id' => (int)$row[$pk]]);
                    }
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                App::log("Crypto migrate table {$table}: " . $e->getMessage(), 'error');
            }
        }
        return $stats;
    }

    private static function key(): ?string
    {
        if (self::$keyResolved) {
            return self::$key;
        }
        self::$keyResolved = true;
        $encoded = App::config('app_key');
        if (!is_string($encoded) || $encoded === '') {
            self::$key = null;
            return null;
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) !== 32) {
            // Allow raw 64-char hex
            if (preg_match('/^[0-9a-fA-F]{64}$/', $encoded)) {
                $raw = hex2bin($encoded);
            }
        }
        if ($raw === false || strlen((string)$raw) !== 32) {
            App::log('Crypto: app_key must be base64 of 32 bytes', 'error');
            self::$key = null;
            return null;
        }
        self::$key = $raw;
        return self::$key;
    }

    /** Reset cached key (after config reload). */
    public static function reset(): void
    {
        self::$key = null;
        self::$keyResolved = false;
    }
}
