<?php
/**
 * WinDCIM - SQL Server PDO wrapper
 *
 * Supports pdo_sqlsrv and pdo_odbc (ODBC Driver 17/18).
 * ODBC does not implement PDO::quote() and often rejects named parameters,
 * so this layer normalizes to positional placeholders when needed.
 */
declare(strict_types=1);

class Database
{
    private static ?PDO $pdo = null;
    private static array $config = [];
    private static ?string $driver = null; // 'sqlsrv' | 'odbc'

    public static function configure(array $config): void
    {
        self::$config = $config;
        self::$pdo = null;
        self::$driver = null;
    }

    public static function driverName(): string
    {
        if (self::$driver !== null) {
            return self::$driver;
        }
        $drivers = PDO::getAvailableDrivers();
        if (in_array('sqlsrv', $drivers, true)) {
            self::$driver = 'sqlsrv';
        } elseif (in_array('odbc', $drivers, true)) {
            self::$driver = 'odbc';
        } else {
            self::$driver = '';
        }
        return self::$driver;
    }

    public static function isOdbc(): bool
    {
        return self::driverName() === 'odbc';
    }

    /**
     * Escape a string literal for T-SQL (N'...' style without the quotes).
     * Used when PDO::quote() is unavailable (pdo_odbc).
     */
    public static function escapeString(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /** Return a safely quoted T-SQL string literal, e.g. N'O''Brien' */
    public static function quoteString(string $value): string
    {
        return "N'" . self::escapeString($value) . "'";
    }

    /**
     * Build a PDO DSN for SQL Server.
     * - pdo_sqlsrv / ODBC Driver 17/18: Encrypt and TrustServerCertificate as yes|no
     */
    private static function buildDsn(array $config, bool $includeDatabase): string
    {
        $host = $config['host'] ?? 'localhost';
        $port = (int)($config['port'] ?? 1433);
        $db   = $config['database'] ?? 'WinDCIM';
        $encryptOn = !empty($config['encrypt']);
        $trustOn = !empty($config['trust_server_certificate']);

        // Named instances: host may be "SERVER\INSTANCE" — do not append ,port
        $server = $host;
        if ($port > 0 && strpos($host, '\\') === false && strpos($host, ',') === false) {
            $server = "{$host},{$port}";
        }

        $encrypt = $encryptOn ? 'yes' : 'no';
        $trust = $trustOn ? 'yes' : 'no';

        $drivers = PDO::getAvailableDrivers();
        if (in_array('sqlsrv', $drivers, true)) {
            $dsn = "sqlsrv:Server={$server};Encrypt={$encrypt};TrustServerCertificate={$trust}";
            if ($includeDatabase) {
                $dsn .= ";Database={$db}";
            }
            return $dsn;
        }

        if (in_array('odbc', $drivers, true)) {
            $driverName = $config['odbc_driver'] ?? 'ODBC Driver 18 for SQL Server';
            $dsn = "odbc:Driver={{$driverName}};Server={$server};Encrypt={$encrypt};TrustServerCertificate={$trust}";
            if ($includeDatabase) {
                $dsn .= ";Database={$db}";
            }
            return $dsn;
        }

        throw new RuntimeException(
            'No SQL Server PDO driver found. Install php_pdo_sqlsrv or php_pdo_odbc with ODBC Driver 17/18 for SQL Server.'
        );
    }

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $user = self::$config['username'] ?? '';
        $pass = self::$config['password'] ?? '';
        $dsn = self::buildDsn(self::$config, true);

        // Never emulate prepares on ODBC: emulation uses quote() which ODBC cannot do.
        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        self::driverName(); // cache

        return self::$pdo;
    }

    /** Connect without database (for CREATE DATABASE during setup) */
    public static function connectServer(array $config): PDO
    {
        $user = $config['username'] ?? '';
        $pass = $config['password'] ?? '';
        $dsn = self::buildDsn($config, false);

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * Convert :name placeholders to ? for drivers that need positional params (ODBC).
     *
     * @param array<string|int, mixed> $params
     * @return array{0: string, 1: list<mixed>}
     */
    private static function normalizeParams(string $sql, array $params): array
    {
        if ($params === []) {
            return [$sql, []];
        }

        // Already purely positional?
        $keys = array_keys($params);
        $positional = $keys === range(0, count($params) - 1);
        if ($positional && strpos($sql, ':') === false) {
            return [$sql, array_values($params)];
        }

        // For sqlsrv, named parameters are fine
        if (!self::isOdbc()) {
            return [$sql, $params];
        }

        // Map :name / name => value
        $map = [];
        foreach ($params as $k => $v) {
            $name = is_string($k) ? ltrim($k, ':') : (string)$k;
            $map[$name] = $v;
            $map[':' . $name] = $v;
        }

        $values = [];
        // Replace :identifiers that are not part of :: cast syntax
        $newSql = preg_replace_callback(
            '/(?<!:):([a-zA-Z_][a-zA-Z0-9_]*)/',
            static function (array $m) use ($map, &$values): string {
                $key = $m[1];
                if (!array_key_exists($key, $map) && !array_key_exists(':' . $key, $map)) {
                    // Leave unknown token alone (should not happen)
                    return $m[0];
                }
                $values[] = $map[$key] ?? $map[':' . $key];
                return '?';
            },
            $sql
        );

        // If SQL already used ? and params were positional, keep values order
        if ($newSql === $sql && $positional) {
            return [$sql, array_values($params)];
        }

        return [$newSql ?? $sql, $values];
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        [$sql, $params] = self::normalizeParams($sql, $params);
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchValue(string $sql, array $params = [])
    {
        $stmt = self::query($sql, $params);
        $val = $stmt->fetchColumn();
        return $val === false ? null : $val;
    }

    /**
     * ODBC Driver 18 + SQL Server DECIMAL is strict: values with more fractional
     * digits than the column scale raise SQLSTATE 22001 "String data, right truncated".
     * Round known columns (and generic floats) before binding.
     */
    public static function normalizeValue(string $column, $value)
    {
        if ($value === null || $value === '') {
            return $value;
        }
        // Only coerce numeric-like values
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return $value;
        }

        static $scale = [
            // rooms / datacenters (meters)
            'width_m' => 2,
            'depth_m' => 2,
            'floor_width_m' => 2,
            'floor_depth_m' => 2,
            // cabinet / room positions (meters, 3 dp in schema)
            'pos_x' => 3,
            'pos_y' => 3,
            'pos_z' => 3,
            'rotation_deg' => 2,
            'max_kw' => 2,
            'max_weight_kg' => 2,
            'weight_kg' => 2,
            'nominal_watts' => 2,
            'metric_value' => 6,
            'rated_amps' => 2,
            'rated_kw' => 2,
            'length_m' => 2,
        ];

        $col = strtolower($column);
        if (isset($scale[$col])) {
            return round((float)$value, $scale[$col]);
        }
        // Integers stay integers
        if (is_int($value) || (is_string($value) && ctype_digit(ltrim($value, '-')))) {
            return is_int($value) ? $value : (int)$value;
        }
        // Unknown float: cap at 4 dp (safe for most DECIMAL(10,x) columns)
        if (is_float($value) || (is_string($value) && str_contains((string)$value, '.'))) {
            return round((float)$value, 4);
        }
        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function normalizeRow(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $out[$k] = self::normalizeValue((string)$k, $v);
        }
        return $out;
    }

    public static function insert(string $table, array $data): int
    {
        $data = self::normalizeRow($data);
        $cols = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $colList = implode(', ', $cols);
        $values = array_values($data);

        // SQL Server: prefer OUTPUT INSERTED for reliable identity retrieval
        $sql = "INSERT INTO {$table} ({$colList}) OUTPUT INSERTED.* VALUES ({$placeholders})";
        try {
            $row = self::query($sql, $values)->fetch();
            if (is_array($row)) {
                foreach ($row as $key => $val) {
                    if (is_string($key) && str_ends_with(strtolower($key), '_id') && is_numeric($val)) {
                        return (int)$val;
                    }
                }
                $first = reset($row);
                if (is_numeric($first)) {
                    return (int)$first;
                }
            }
        } catch (Throwable $e) {
            // Fallback without OUTPUT (some ODBC edge cases)
            $sql = "INSERT INTO {$table} ({$colList}) VALUES ({$placeholders})";
            self::query($sql, $values);
        }

        try {
            $id = self::connection()->lastInsertId();
            if ($id) {
                return (int)$id;
            }
        } catch (Throwable $e) {
            // ODBC often throws / returns empty for lastInsertId
        }

        $id = self::fetchValue('SELECT CAST(SCOPE_IDENTITY() AS INT)');
        if ($id !== null && $id !== '') {
            return (int)$id;
        }
        $id = self::fetchValue('SELECT CAST(@@IDENTITY AS INT)');
        return (int)($id ?? 0);
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $data = self::normalizeRow($data);
        $sets = [];
        $params = [];
        foreach ($data as $k => $v) {
            // Positional SET values — works on both sqlsrv and ODBC
            $sets[] = "{$k} = ?";
            $params[] = $v;
        }

        // Convert named WHERE params (:id) to positional for ODBC compatibility
        $wherePositional = $where;
        $whereValues = [];
        if (preg_match('/:[a-zA-Z_]/', $where)) {
            $map = [];
            foreach ($whereParams as $k => $v) {
                $name = is_string($k) ? ltrim($k, ':') : (string)$k;
                $map[$name] = $v;
            }
            $wherePositional = preg_replace_callback(
                '/(?<!:):([a-zA-Z_][a-zA-Z0-9_]*)/',
                static function (array $m) use ($map, &$whereValues): string {
                    $key = $m[1];
                    if (!array_key_exists($key, $map)) {
                        return $m[0];
                    }
                    $whereValues[] = $map[$key];
                    return '?';
                },
                $where
            ) ?? $where;
        } else {
            $whereValues = array_values($whereParams);
        }

        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $sets), $wherePositional);
        $stmt = self::query($sql, array_merge($params, $whereValues));
        return $stmt->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        $stmt = self::query("DELETE FROM {$table} WHERE {$where}", $params);
        return $stmt->rowCount();
    }

    public static function beginTransaction(): void
    {
        self::connection()->beginTransaction();
    }

    public static function commit(): void
    {
        self::connection()->commit();
    }

    public static function rollBack(): void
    {
        if (self::connection()->inTransaction()) {
            self::connection()->rollBack();
        }
    }

    /** Split and execute a multi-statement SQL script (GO batches) */
    public static function executeScript(PDO $pdo, string $script): void
    {
        // Normalize line endings and split on GO batch separator
        $script = str_replace(["\r\n", "\r"], "\n", $script);
        $batches = preg_split('/^\s*GO\s*$/mi', $script) ?: [];

        foreach ($batches as $batch) {
            $batch = trim($batch);
            if ($batch === '' || str_starts_with($batch, '--') && strlen(trim(preg_replace('/--.*$/m', '', $batch) ?? '')) === 0) {
                // Skip empty / comment-only batches carefully
                $stripped = trim(preg_replace('/--.*$/m', '', $batch) ?? '');
                if ($stripped === '') {
                    continue;
                }
            }
            if ($batch === '') {
                continue;
            }
            try {
                $pdo->exec($batch);
            } catch (PDOException $e) {
                // Ignore "already exists" style errors for IF NOT EXISTS patterns that still throw
                $msg = $e->getMessage();
                if (stripos($msg, 'already an object named') !== false
                    || stripos($msg, 'already exists') !== false) {
                    continue;
                }
                throw new RuntimeException("SQL batch failed: {$msg}\n---\n" . substr($batch, 0, 400), 0, $e);
            }
        }
    }
}
