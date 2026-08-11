<?php
/**
 * ColdAisle — Tech mode (field / tablet / phone chrome).
 *
 * Design (upgrade-safe):
 * - Session flag only. Same pages, APIs, helpers, and permissions as desktop.
 * - No business logic lives here — audits, power map, work orders, devices stay
 *   in their existing modules. Tech mode only swaps chrome + hub entry points.
 * - Surfaces are a declarative registry so new field tools register once and
 *   appear in the bottom nav without forking pages.
 * - QR / legacy ?field=1 enable the same session mode (cabinet_label deep links).
 *
 * Enable:  TechMode::enable() or ?mode=tech or ?field=1
 * Disable: TechMode::disable() or ?mode=full|desktop|0
 */
declare(strict_types=1);

class TechMode
{
    public const SESSION_KEY = 'coldaisle_tech_mode';
    public const RECENT_KEY = 'coldaisle_tech_recent_cabs';
    public const RECENT_MAX = 12;

    /** @var list<array<string,mixed>>|null */
    private static ?array $surfaceCache = null;

    /**
     * Call once per request after session start (from App::boot).
     * Handles ?mode= and legacy ?field= without page-specific forks.
     */
    public static function bootstrapFromRequest(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $mode = isset($_GET['mode']) ? strtolower(trim((string)$_GET['mode'])) : '';
        if ($mode === 'tech' || $mode === '1' || $mode === 'field') {
            self::enable();
        } elseif (in_array($mode, ['full', 'desktop', '0', 'off', 'exit'], true)) {
            self::disable();
        }

        // Legacy QR field mode → same session flag (cabinet plaques)
        if (isset($_GET['field'])) {
            $f = strtolower(trim((string)$_GET['field']));
            if ($f !== '' && $f !== '0' && $f !== 'false' && $f !== 'no') {
                self::enable();
            }
        }
    }

    public static function isActive(): bool
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        return !empty($_SESSION[self::SESSION_KEY]);
    }

    public static function enable(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_KEY] = 1;
        }
    }

    public static function disable(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::SESSION_KEY]);
        }
    }

    /**
     * True when field/tech chrome should apply (session or one-shot field=1).
     * Prefer isActive() after bootstrap; this covers mid-request edge cases.
     */
    public static function wantsFieldChrome(): bool
    {
        if (self::isActive()) {
            return true;
        }
        if (isset($_GET['field'])) {
            $f = strtolower(trim((string)$_GET['field']));
            return $f !== '' && $f !== '0' && $f !== 'false' && $f !== 'no';
        }
        return false;
    }

    /**
     * Declarative field surfaces. Add new technician entry points here only —
     * do not copy audit/power/device logic into a second page.
     *
     * Keys:
     *   id, label, icon, path (app-relative), nav (bottom bar?),
     *   nav_key (AuthManager canViewNav), active (layout active keys),
     *   hub (show on tech home cards?)
     *
     * @return list<array{
     *   id:string,label:string,icon:string,path:string,nav:bool,
     *   nav_key:?string,active:list<string>,hub:bool,hint:string
     * }>
     */
    public static function surfaces(): array
    {
        if (self::$surfaceCache !== null) {
            return self::$surfaceCache;
        }

        // Core registry — extend this list when shipping new field offerings.
        $list = [
            [
                'id' => 'hub',
                'label' => 'Home',
                'icon' => '⌂',
                'path' => 'pages/tech.php',
                'nav' => true,
                'nav_key' => null,
                'active' => ['tech'],
                'hub' => false,
                'hint' => 'Technician hub',
            ],
            [
                'id' => 'cabinets',
                'label' => 'Cabinets',
                'icon' => '▤',
                'path' => 'pages/cabinets.php',
                'nav' => true,
                'nav_key' => 'cabinets',
                'active' => ['cabinets'],
                'hub' => true,
                'hint' => 'Elevations, audit, QR scan target',
            ],
            [
                'id' => 'devices',
                'label' => 'Devices',
                'icon' => '🖥',
                'path' => 'pages/devices.php',
                'nav' => true,
                'nav_key' => 'devices',
                'active' => ['devices', 'device_templates'],
                'hub' => true,
                'hint' => 'Identity, power map, notes',
            ],
            [
                'id' => 'work_orders',
                'label' => 'Work',
                'icon' => '📋',
                'path' => 'pages/work_orders.php',
                'nav' => true,
                'nav_key' => 'work_orders',
                'active' => ['work_orders'],
                'hub' => true,
                'hint' => 'Moves and change tickets',
            ],
            [
                'id' => 'disposals',
                'label' => 'Decom',
                'icon' => '🗑',
                'path' => 'pages/disposals.php',
                'nav' => false,
                'nav_key' => 'disposals',
                'active' => ['disposals'],
                'hub' => true,
                'hint' => 'Decommission checklist (optional)',
            ],
            [
                'id' => 'audits',
                'label' => 'Audits',
                'icon' => '✓',
                'path' => 'pages/audits.php',
                'nav' => false,
                'nav_key' => 'audits',
                'active' => ['audits'],
                'hub' => true,
                'hint' => 'Audit history / jobs',
            ],
            [
                'id' => 'power_pdus',
                'label' => 'PDUs',
                'icon' => '⚡',
                'path' => 'pages/power_pdus.php',
                'nav' => false,
                'nav_key' => 'power',
                'active' => ['power_pdus', 'power'],
                'hub' => true,
                'hint' => 'PDU inventory (power map lives on device)',
            ],
        ];

        /**
         * Allow plugins / future includes to append surfaces without editing this file.
         * Example: App hooks or a drop-in under includes/tech_surfaces.php
         */
        $extraFile = App::ROOT . '/includes/tech_surfaces.php';
        if (is_file($extraFile)) {
            try {
                $extra = include $extraFile;
                if (is_array($extra)) {
                    foreach ($extra as $row) {
                        if (is_array($row) && !empty($row['id']) && !empty($row['path'])) {
                            $list[] = array_merge([
                                'label' => (string)$row['id'],
                                'icon' => '·',
                                'nav' => false,
                                'nav_key' => null,
                                'active' => [(string)$row['id']],
                                'hub' => false,
                                'hint' => '',
                            ], $row);
                        }
                    }
                }
            } catch (Throwable $e) {
                App::log('TechMode surfaces extra: ' . $e->getMessage(), 'warning');
            }
        }

        self::$surfaceCache = $list;
        return $list;
    }

    /**
     * Bottom-nav surfaces the user may open.
     *
     * @return list<array<string,mixed>>
     */
    public static function navSurfaces(array $user): array
    {
        $out = [];
        foreach (self::surfaces() as $s) {
            if (empty($s['nav'])) {
                continue;
            }
            $key = $s['nav_key'] ?? null;
            if ($key !== null && $key !== '' && !AuthManager::canViewNav($user, (string)$key)) {
                continue;
            }
            $out[] = $s;
        }
        return $out;
    }

    /**
     * Hub cards (optional surfaces with hub=true).
     *
     * @return list<array<string,mixed>>
     */
    public static function hubSurfaces(array $user): array
    {
        $out = [];
        foreach (self::surfaces() as $s) {
            if (empty($s['hub'])) {
                continue;
            }
            $key = $s['nav_key'] ?? null;
            if ($key !== null && $key !== '' && !AuthManager::canViewNav($user, (string)$key)) {
                continue;
            }
            $out[] = $s;
        }
        return $out;
    }

    public static function surfaceUrl(array $surface): string
    {
        $path = ltrim((string)($surface['path'] ?? ''), '/');
        return App::url($path);
    }

    public static function hubUrl(): string
    {
        return App::url('pages/tech.php');
    }

    public static function enableUrl(?string $returnPath = null): string
    {
        $ret = $returnPath !== null && $returnPath !== ''
            ? App::safeReturnPath($returnPath, 'pages/tech.php')
            : 'pages/tech.php';
        // mode=tech then land on return (hub by default)
        if ($ret === 'pages/tech.php' || $ret === 'index.php') {
            return App::url('pages/tech.php?mode=tech');
        }
        $sep = str_contains($ret, '?') ? '&' : '?';
        return App::url($ret . $sep . 'mode=tech');
    }

    public static function disableUrl(?string $returnPath = null): string
    {
        $ret = $returnPath !== null && $returnPath !== ''
            ? App::safeReturnPath($returnPath, 'index.php')
            : 'index.php';
        $sep = str_contains($ret, '?') ? '&' : '?';
        return App::url($ret . $sep . 'mode=full');
    }

    /**
     * Deep link for QR plaques — enables tech mode on open.
     * Single place for label generators (cabinet_label.php).
     */
    public static function cabinetFieldUrl(int $cabinetId, bool $openAudit = false): string
    {
        $q = 'pages/cabinets.php?id=' . max(0, $cabinetId) . '&mode=tech';
        if ($openAudit) {
            $q .= '&audit=1';
        }
        return App::url($q);
    }

    /** Remember cabinet for hub “Recent” (no extra tables). */
    public static function rememberCabinet(int $cabinetId, string $name = ''): void
    {
        if ($cabinetId < 1 || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $list = self::recentCabinets();
        $list = array_values(array_filter(
            $list,
            static fn($r) => (int)($r['id'] ?? 0) !== $cabinetId
        ));
        array_unshift($list, [
            'id' => $cabinetId,
            'name' => $name !== '' ? $name : ('Cabinet #' . $cabinetId),
            'at' => time(),
        ]);
        $list = array_slice($list, 0, self::RECENT_MAX);
        $_SESSION[self::RECENT_KEY] = $list;
    }

    /**
     * @return list<array{id:int,name:string,at:int}>
     */
    public static function recentCabinets(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }
        $raw = $_SESSION[self::RECENT_KEY] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $r) {
            if (!is_array($r) || empty($r['id'])) {
                continue;
            }
            $out[] = [
                'id' => (int)$r['id'],
                'name' => (string)($r['name'] ?? ('#' . $r['id'])),
                'at' => (int)($r['at'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Whether layout active key matches a surface (for bottom-nav highlight).
     */
    public static function surfaceIsActive(array $surface, string $layoutActive): bool
    {
        $keys = $surface['active'] ?? [];
        if (!is_array($keys)) {
            return false;
        }
        return in_array($layoutActive, $keys, true);
    }
}
