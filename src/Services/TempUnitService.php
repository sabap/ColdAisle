<?php
/**
 * Site-wide temperature unit (°C / °F).
 * Storage is always canonical Celsius; convert at the UI/API boundary.
 */
declare(strict_types=1);

class TempUnitService
{
    public const SETTING = 'temp_unit';
    public const C = 'C';
    public const F = 'F';

    private static ?string $cached = null;

    public static function clearCache(): void
    {
        self::$cached = null;
    }

    /** Site preference: C (default) or F. */
    public static function siteUnit(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }
        $u = 'C';
        if (class_exists('SettingsService')) {
            try {
                $raw = strtoupper(trim((string)SettingsService::get(self::SETTING, 'C')));
                if ($raw === 'F' || $raw === 'FAHRENHEIT') {
                    $u = 'F';
                }
            } catch (Throwable $e) {
                $u = 'C';
            }
        }
        self::$cached = $u;
        return $u;
    }

    public static function isFahrenheit(): bool
    {
        return self::siteUnit() === self::F;
    }

    /** Display symbol including degree sign. */
    public static function symbol(): string
    {
        return self::isFahrenheit() ? '°F' : '°C';
    }

    /** Short label for forms: °C or °F. */
    public static function label(): string
    {
        return self::symbol();
    }

    public static function saveFromPost(array $post): void
    {
        $raw = strtoupper(trim((string)($post['temp_unit'] ?? 'C')));
        $u = ($raw === 'F' || $raw === 'FAHRENHEIT') ? self::F : self::C;
        SettingsService::set(self::SETTING, $u, 'display');
        self::$cached = $u;
    }

    /**
     * Sensor kinds whose primary value / thresholds are temperatures (not humidity-only).
     */
    public static function isTempKind(string $kind): bool
    {
        return in_array($kind, ['temperature', 'temp_humidity', 'dew_point'], true);
    }

    /** °C → display unit. */
    public static function fromC(?float $celsius): ?float
    {
        if ($celsius === null) {
            return null;
        }
        if (!self::isFahrenheit()) {
            return $celsius;
        }
        return ($celsius * 9.0 / 5.0) + 32.0;
    }

    /** Display unit → °C. */
    public static function toC(?float $display): ?float
    {
        if ($display === null) {
            return null;
        }
        if (!self::isFahrenheit()) {
            return $display;
        }
        return ($display - 32.0) * 5.0 / 9.0;
    }

    /**
     * Format a stored °C value for UI (no unit suffix unless $withSymbol).
     */
    public static function format(?float $celsius, int $decimals = 1, bool $withSymbol = false): string
    {
        if ($celsius === null) {
            return '—';
        }
        $v = self::fromC($celsius);
        if ($v === null) {
            return '—';
        }
        $s = rtrim(rtrim(number_format($v, $decimals, '.', ''), '0'), '.');
        if ($s === '' || $s === '-') {
            $s = '0';
        }
        return $withSymbol ? ($s . ' ' . self::symbol()) : $s;
    }

    /**
     * Convert a post field that may be empty into nullable °C for storage.
     */
    public static function postToC(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw)) {
            return null;
        }
        return self::toC((float)$raw);
    }

    /**
     * Convert temperature threshold fields from display → °C for save.
     * Humidity-only kinds left unchanged.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public static function thresholdsDisplayToStorage(array $fields, string $kind): array
    {
        if (!self::isTempKind($kind)) {
            return $fields;
        }
        foreach (['warn_low', 'warn_high', 'crit_low', 'crit_high'] as $k) {
            if (!array_key_exists($k, $fields)) {
                continue;
            }
            $v = $fields[$k];
            if ($v === null || $v === '') {
                $fields[$k] = null;
                continue;
            }
            if (is_numeric($v)) {
                $fields[$k] = self::toC((float)$v);
            }
        }
        return $fields;
    }

    /**
     * Default unit string for a sensor kind under current site preference.
     */
    public static function defaultUnitForKind(string $kind): string
    {
        return match ($kind) {
            'temperature', 'dew_point' => self::symbol(),
            'humidity' => '%RH',
            'temp_humidity' => self::symbol() . ' / %RH',
            'differential_pressure' => 'Pa',
            'airflow' => 'CFM',
            'leak' => 'state',
            default => '',
        };
    }
}
