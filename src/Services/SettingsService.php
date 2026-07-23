<?php
/**
 * WinDCIM - Key/value settings
 */
declare(strict_types=1);

class SettingsService
{
    private static array $cache = [];

    public static function get(string $key, $default = null)
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        try {
            $val = Database::fetchValue('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
            self::$cache[$key] = $val !== null ? $val : $default;
            return self::$cache[$key];
        } catch (Throwable $e) {
            return $default;
        }
    }

    public static function set(string $key, $value, string $category = 'general'): void
    {
        $exists = Database::fetchValue('SELECT 1 FROM settings WHERE setting_key = ?', [$key]);
        if ($exists) {
            Database::update('settings', [
                'setting_value' => (string)$value,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'setting_key = :k', [':k' => $key]);
        } else {
            Database::insert('settings', [
                'setting_key' => $key,
                'setting_value' => (string)$value,
                'category' => $category,
            ]);
        }
        self::$cache[$key] = (string)$value;
    }

    public static function all(?string $category = null): array
    {
        if ($category) {
            return Database::fetchAll('SELECT * FROM settings WHERE category = ? ORDER BY setting_key', [$category]);
        }
        return Database::fetchAll('SELECT * FROM settings ORDER BY category, setting_key');
    }
}
