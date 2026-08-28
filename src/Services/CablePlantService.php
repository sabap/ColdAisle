<?php
/**
 * ColdAisle — structured cable plant helpers (G-B1).
 *
 * Single place for media/speed/raceway catalogs and path geometry.
 * Cables page, floorplan, and APIs consume this — no forked definitions.
 */
declare(strict_types=1);

class CablePlantService
{
    /** @return array<string,string> role => label */
    public static function cableRoles(): array
    {
        return [
            'patch' => 'Patch cord',
            'structured' => 'Structured / permanent link',
            'trunk' => 'Trunk / multipair / harness',
            'jumper' => 'Jumper / cross-connect',
        ];
    }

    /**
     * Media presets (value stored on cables.media_type).
     * @return list<array{value:string,label:string,class:string,default_speed:?string,default_color:string}>
     */
    public static function mediaPresets(): array
    {
        return [
            ['value' => 'Cat6', 'label' => 'Cat6 UTP', 'class' => 'copper', 'default_speed' => '1G', 'default_color' => '#2563eb'],
            ['value' => 'Cat6A', 'label' => 'Cat6A', 'class' => 'copper', 'default_speed' => '10G', 'default_color' => '#1d4ed8'],
            ['value' => 'Cat5e', 'label' => 'Cat5e', 'class' => 'copper', 'default_speed' => '1G', 'default_color' => '#3b82f6'],
            ['value' => 'DAC', 'label' => 'DAC (copper twinax)', 'class' => 'copper', 'default_speed' => '10G', 'default_color' => '#64748b'],
            ['value' => 'OM3', 'label' => 'OM3 multimode fiber', 'class' => 'fiber', 'default_speed' => '10G', 'default_color' => '#eab308'],
            ['value' => 'OM4', 'label' => 'OM4 multimode fiber', 'class' => 'fiber', 'default_speed' => '40G', 'default_color' => '#ca8a04'],
            ['value' => 'OM5', 'label' => 'OM5 multimode fiber', 'class' => 'fiber', 'default_speed' => '100G', 'default_color' => '#a16207'],
            ['value' => 'OS2', 'label' => 'OS2 single-mode fiber', 'class' => 'fiber', 'default_speed' => '100G', 'default_color' => '#facc15'],
            ['value' => 'AOC', 'label' => 'AOC (active optical)', 'class' => 'fiber', 'default_speed' => '100G', 'default_color' => '#f59e0b'],
            ['value' => 'Power', 'label' => 'Power cord / whip', 'class' => 'power', 'default_speed' => null, 'default_color' => '#000000'],
        ];
    }

    /** @return list<string> */
    public static function speedOptions(): array
    {
        return ['100M', '1G', '2.5G', '5G', '10G', '25G', '40G', '50G', '100G', '200G', '400G'];
    }

    /**
     * Suggested jacket / map colors by speed (industry-ish, overridable per cable).
     * @return array<string,string> speed => #hex
     */
    public static function speedColors(): array
    {
        return [
            '100M' => '#94a3b8',
            '1G' => '#2563eb',
            '2.5G' => '#3b82f6',
            '5G' => '#0ea5e9',
            '10G' => '#f97316',
            '25G' => '#ea580c',
            '40G' => '#a855f7',
            '50G' => '#9333ea',
            '100G' => '#eab308',
            '200G' => '#ca8a04',
            '400G' => '#dc2626',
        ];
    }

    public const DEFAULT_FILLET_RADIUS_M = 0.30;

    /** Typical overhead ladder / fiber tray height (m AFF). */
    public const DEFAULT_ELEVATION_OVERHEAD_M = 2.70;

    /** Typical under raised-floor path (m; negative = below finished floor). */
    public const DEFAULT_ELEVATION_UNDERFLOOR_M = -0.30;

    /** Default tray widths (m). */
    public const DEFAULT_WIDTH_LADDER_M = 0.30;
    public const DEFAULT_WIDTH_FIBER_M = 0.15;
    public const DEFAULT_WIDTH_U_CHANNEL_M = 0.10;
    public const DEFAULT_WIDTH_CONDUIT_M = 0.05;

    /**
     * Typical mount: fiber U-channel brackets on ladder, ~10 in above ladder rails.
     * Used as elevation offset when cloning ladder → U-channel.
     */
    public const DEFAULT_U_CHANNEL_ELEV_OFFSET_M = 0.254;

    /**
     * Raceway construction type (3D-ready). Primary: ladder, fiber_u_channel, fiber_raceway, conduit.
     * @return array<string,string>
     */
    public static function pathKinds(): array
    {
        return [
            'ladder' => 'Ladder tray',
            'fiber_u_channel' => 'Fiber U-channel',
            'fiber_raceway' => 'Fiber raceway / trough',
            'conduit' => 'Conduit',
            // Advanced / legacy
            'tray' => 'Cable tray (generic)',
            'fiber_trough' => 'Fiber trough (legacy)',
            'raceway' => 'Basket raceway',
            'underfloor' => 'Underfloor channel',
            'busway' => 'Busway / power path',
            'other' => 'Other',
        ];
    }

    /** Default elevation (m AFF) from cabinet feed mode. */
    public static function defaultElevationForFeed(string $feed): float
    {
        $feed = strtolower(trim($feed));
        return $feed === 'underfloor'
            ? self::DEFAULT_ELEVATION_UNDERFLOOR_M
            : self::DEFAULT_ELEVATION_OVERHEAD_M;
    }

    /** Default trough / ladder width (m) from path kind. */
    public static function defaultWidthForKind(string $kind): float
    {
        return match (self::normalizePathKind($kind)) {
            'conduit' => self::DEFAULT_WIDTH_CONDUIT_M,
            'fiber_u_channel' => self::DEFAULT_WIDTH_U_CHANNEL_M,
            'fiber_raceway', 'fiber_trough' => self::DEFAULT_WIDTH_FIBER_M,
            default => self::DEFAULT_WIDTH_LADDER_M,
        };
    }

    /** Primary kinds shown in finish UI. @return list<string> */
    public static function primaryPathKinds(): array
    {
        return ['ladder', 'fiber_u_channel', 'fiber_raceway', 'conduit'];
    }

    /**
     * Networks for routing / 3D visibility filters.
     * @return array<string,array{label:string,path_kinds:list<string>,media_class:?string}>
     */
    public static function racewayNetworks(): array
    {
        return [
            'all' => [
                'label' => 'All raceways',
                'path_kinds' => [],
                'media_class' => null,
            ],
            'ladder' => [
                'label' => 'Ladder tray',
                'path_kinds' => ['ladder', 'tray'],
                'media_class' => null,
            ],
            'fiber' => [
                'label' => 'Fiber (U-channel + trough)',
                'path_kinds' => ['fiber_u_channel', 'fiber_raceway', 'fiber_trough'],
                'media_class' => 'fiber',
            ],
            'fiber_u_channel' => [
                'label' => 'Fiber U-channel only',
                'path_kinds' => ['fiber_u_channel'],
                'media_class' => 'fiber',
            ],
            'fiber_raceway' => [
                'label' => 'Fiber trough only',
                'path_kinds' => ['fiber_raceway', 'fiber_trough'],
                'media_class' => 'fiber',
            ],
            'conduit' => [
                'label' => 'Conduit',
                'path_kinds' => ['conduit'],
                'media_class' => null,
            ],
            'copper' => [
                'label' => 'Copper / mixed trays',
                'path_kinds' => ['ladder', 'tray', 'raceway'],
                'media_class' => 'copper',
            ],
        ];
    }

    /** @return array<string,string> */
    public static function segmentClasses(): array
    {
        return [
            'rs' => 'RS — row span (along row / aisle)',
            'orc' => 'ORC — outer row connector',
            'irc' => 'IRC — inner row connector',
            'custom' => 'Custom code',
        ];
    }

    /** Normalize legacy kind aliases to current codes. */
    public static function normalizePathKind(string $kind): string
    {
        $k = strtolower(trim($kind));
        $map = [
            'fiber_trough' => 'fiber_raceway',
            'fiber' => 'fiber_raceway',
            'u_channel' => 'fiber_u_channel',
            'uchannel' => 'fiber_u_channel',
            'fiber_u' => 'fiber_u_channel',
            'fiber-u-channel' => 'fiber_u_channel',
            'u-channel' => 'fiber_u_channel',
            'tray' => 'ladder',
            'overhead' => 'ladder',
            'basket' => 'raceway',
        ];
        if (isset($map[$k])) {
            $k = $map[$k];
        }
        $kinds = self::pathKinds();
        return isset($kinds[$k]) ? $k : 'ladder';
    }

    public static function defaultColorForPathKind(string $kind): string
    {
        return match (self::normalizePathKind($kind)) {
            'fiber_u_channel', 'fiber_raceway', 'fiber_trough' => '#eab308',
            'ladder', 'tray' => '#2563eb',
            'conduit' => '#64748b',
            'busway' => '#111827',
            default => '#38bdf8',
        };
    }

    /**
     * Clone a raceway: same plan geometry (centerline + 90° fillets), new type/elev/code.
     * U-channel on ladder brackets: same XY routing, typically +10 in elevation.
     *
     * @param array{
     *   path_kind?:string,media_class?:string,path_code?:string,name?:string,
     *   elevation_m?:float|string|null,elevation_offset_m?:float|string|null,
     *   width_m?:float|string|null,color_hex?:string,feed_to?:string,
     *   code_prefix?:string,code_suffix?:string
     * } $options
     * @return array{ok:bool,path_id:int,message:string,path?:array<string,mixed>}
     */
    public static function clonePath(int $sourcePathId, array $options = []): array
    {
        if ($sourcePathId < 1) {
            return ['ok' => false, 'path_id' => 0, 'message' => 'Source path required.'];
        }
        try {
            $src = Database::fetchOne('SELECT * FROM cable_paths WHERE path_id = ?', [$sourcePathId]);
        } catch (Throwable $e) {
            return ['ok' => false, 'path_id' => 0, 'message' => $e->getMessage()];
        }
        if (!$src) {
            return ['ok' => false, 'path_id' => 0, 'message' => 'Source raceway not found.'];
        }
        $pts = self::parseWaypoints($src['waypoints'] ?? null);
        if (count($pts) < 2) {
            return ['ok' => false, 'path_id' => 0, 'message' => 'Source raceway needs at least two waypoints.'];
        }
        // Plan geometry only — never inherit per-vertex z (would force clone to source height in 3D)
        foreach ($pts as &$pt) {
            unset($pt['z']);
        }
        unset($pt);

        $newKind = self::normalizePathKind((string)($options['path_kind'] ?? 'fiber_u_channel'));
        $media = strtolower(trim((string)($options['media_class']
            ?? (in_array($newKind, ['fiber_u_channel', 'fiber_raceway', 'fiber_trough'], true) ? 'fiber' : ($src['media_class'] ?? 'mixed')))));
        if (!isset(self::mediaClasses()[$media])) {
            $media = 'fiber';
        }
        $feed = strtolower(trim((string)($options['feed_to'] ?? $src['feed_to'] ?? 'overhead')));
        if (!isset(self::feedModes()[$feed])) {
            $feed = 'overhead';
        }

        $srcElev = isset($src['elevation_m']) && $src['elevation_m'] !== null && $src['elevation_m'] !== ''
            ? (float)$src['elevation_m']
            : self::defaultElevationForFeed($feed);
        if (array_key_exists('elevation_m', $options) && $options['elevation_m'] !== '' && $options['elevation_m'] !== null) {
            $elev = (float)$options['elevation_m'];
        } else {
            $offset = array_key_exists('elevation_offset_m', $options) && $options['elevation_offset_m'] !== '' && $options['elevation_offset_m'] !== null
                ? (float)$options['elevation_offset_m']
                : ($newKind === 'fiber_u_channel' ? self::DEFAULT_U_CHANNEL_ELEV_OFFSET_M : 0.0);
            $elev = $srcElev + $offset;
        }
        $elev = max(-2.0, min(12.0, $elev));

        $width = array_key_exists('width_m', $options) && $options['width_m'] !== '' && $options['width_m'] !== null
            ? (float)$options['width_m']
            : self::defaultWidthForKind($newKind);
        $width = max(0.03, min(5.0, $width));

        $color = trim((string)($options['color_hex'] ?? ''));
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $color = self::defaultColorForPathKind($newKind);
        }

        $srcCode = trim((string)($src['path_code'] ?? $src['name'] ?? ''));
        $pathCode = trim((string)($options['path_code'] ?? ''));
        if ($pathCode === '') {
            $prefix = trim((string)($options['code_prefix'] ?? ''));
            $suffix = trim((string)($options['code_suffix'] ?? ''));
            if ($prefix === '' && $suffix === '') {
                // Default: F- prefix for fiber clones of ladder codes
                $prefix = ($newKind === 'fiber_u_channel' || $newKind === 'fiber_raceway') ? 'F-' : 'C-';
            }
            $pathCode = $prefix . $srcCode . $suffix;
        }
        $pathCode = self::uniquePathCodeInRoom((int)($src['room_id'] ?? 0), $pathCode);
        $name = trim((string)($options['name'] ?? ''));
        if ($name === '') {
            $name = $pathCode;
        }

        $data = [
            'room_id' => (int)($src['room_id'] ?? 0),
            'path_code' => $pathCode,
            'name' => $name,
            'path_kind' => $newKind,
            'media_class' => $media,
            'feed_to' => $feed,
            'color_hex' => $color,
            'width_m' => $width,
            'elevation_m' => $elev,
            'segment_class' => $src['segment_class'] ?? null,
            'waypoints_list' => $pts, // exact centerline + fillets (auto-centered on source)
            'notes' => 'Cloned from ' . ($srcCode !== '' ? $srcCode : ('#' . $sourcePathId))
                . ' (path_id ' . $sourcePathId . ')',
            'is_active' => 1,
        ];
        $res = self::savePath($data, null);
        if (empty($res['ok'])) {
            return $res;
        }
        $newId = (int)$res['path_id'];
        // Ensure elevation stuck (upgrade fallback can drop new columns on first insert)
        try {
            $check = Database::fetchOne(
                'SELECT elevation_m, path_kind FROM cable_paths WHERE path_id = ?',
                [$newId]
            );
            $savedElev = isset($check['elevation_m']) && $check['elevation_m'] !== null && $check['elevation_m'] !== ''
                ? (float)$check['elevation_m']
                : null;
            if ($savedElev === null || abs($savedElev - $elev) > 0.001) {
                Database::update(
                    'cable_paths',
                    [
                        'elevation_m' => $elev,
                        'width_m' => $width,
                        'path_kind' => $newKind,
                        'media_class' => $media,
                        'color_hex' => $color,
                    ],
                    'path_id = :id',
                    [':id' => $newId]
                );
            }
        } catch (Throwable $e) {
            // non-fatal — row may still be usable
        }
        $row = null;
        try {
            $row = Database::fetchOne('SELECT * FROM cable_paths WHERE path_id = ?', [$newId]);
            if ($row) {
                $row['waypoints_list'] = self::parseWaypoints($row['waypoints'] ?? null);
                // Normalize numeric types for JSON/JS
                if (isset($row['elevation_m']) && $row['elevation_m'] !== null && $row['elevation_m'] !== '') {
                    $row['elevation_m'] = (float)$row['elevation_m'];
                }
                if (isset($row['width_m']) && $row['width_m'] !== null && $row['width_m'] !== '') {
                    $row['width_m'] = (float)$row['width_m'];
                }
            }
        } catch (Throwable $e) {
        }
        return [
            'ok' => true,
            'path_id' => $newId,
            'message' => 'Cloned ' . ($srcCode !== '' ? $srcCode : ('#' . $sourcePathId))
                . ' → ' . $pathCode . ' (' . (self::pathKinds()[$newKind] ?? $newKind)
                . ', elev ' . number_format($elev, 2) . ' m AFF)',
            'path' => $row,
            'elevation_m' => $elev,
        ];
    }

    /**
     * Clone every raceway of a given kind in a room (e.g. all ladders → U-channel).
     *
     * @param array<string,mixed> $options passed to clonePath
     * @return array{ok:bool,message:string,created:list<array>,skipped:int}
     */
    public static function clonePathsByKindInRoom(int $roomId, string $sourceKind = 'ladder', array $options = []): array
    {
        if ($roomId < 1) {
            return ['ok' => false, 'message' => 'Room required.', 'created' => [], 'skipped' => 0];
        }
        $sourceKind = self::normalizePathKind($sourceKind);
        $paths = self::pathsForRoom($roomId, true);
        $created = [];
        $skipped = 0;
        $errors = [];
        foreach ($paths as $p) {
            $k = self::normalizePathKind((string)($p['path_kind'] ?? 'ladder'));
            $aliases = match ($sourceKind) {
                'ladder' => ['ladder', 'tray'],
                'fiber_raceway' => ['fiber_raceway', 'fiber_trough'],
                default => [$sourceKind],
            };
            if (!in_array($k, $aliases, true)) {
                continue;
            }
            $res = self::clonePath((int)$p['path_id'], $options);
            if (!empty($res['ok'])) {
                $created[] = [
                    'path_id' => (int)$res['path_id'],
                    'path_code' => $res['path']['path_code'] ?? null,
                    'source_path_id' => (int)$p['path_id'],
                    'message' => $res['message'],
                ];
            } else {
                $skipped++;
                $errors[] = $res['message'] ?? 'clone failed';
            }
        }
        if ($created === [] && $skipped === 0) {
            return [
                'ok' => false,
                'message' => 'No ' . (self::pathKinds()[$sourceKind] ?? $sourceKind) . ' raceways in this room to clone.',
                'created' => [],
                'skipped' => 0,
            ];
        }
        return [
            'ok' => $created !== [],
            'message' => count($created) . ' raceway(s) cloned'
                . ($skipped > 0 ? (', ' . $skipped . ' skipped') : '')
                . ($errors !== [] ? (': ' . $errors[0]) : ''),
            'created' => $created,
            'skipped' => $skipped,
        ];
    }

    /** Ensure path_code is unique in room by appending -2, -3, … */
    public static function uniquePathCodeInRoom(int $roomId, string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            $code = 'PATH';
        }
        if ($roomId < 1) {
            return $code;
        }
        $existing = self::pathCodesInRoom($roomId);
        $base = $code;
        $n = 2;
        while (isset($existing[strtoupper($code)])) {
            $code = $base . '-' . $n;
            $n++;
            if ($n > 500) {
                $code = $base . '-' . time();
                break;
            }
        }
        return $code;
    }

    /**
     * Suggest next free pathway code in a room (RS-A, ORC-AB.1, IRC-AB.1, …).
     *
     * @param 'rs'|'orc'|'irc'|'custom' $class
     * @param string $rowOrPair RS: "A"; ORC/IRC: "AB"
     */
    public static function suggestNextPathCode(int $roomId, string $class, string $rowOrPair = 'A'): string
    {
        $class = strtolower(trim($class));
        $rowOrPair = strtoupper(preg_replace('/[^A-Z]/', '', $rowOrPair) ?? '');
        if ($rowOrPair === '') {
            $rowOrPair = 'A';
        }
        $existing = self::pathCodesInRoom($roomId);
        if ($class === 'rs') {
            $letter = $rowOrPair[0];
            // Prefer requested letter if free; else next free A–Z
            for ($i = 0; $i < 26; $i++) {
                $L = chr(ord('A') + ((ord($letter) - ord('A') + $i) % 26));
                $code = 'RS-' . $L;
                if (!isset($existing[strtoupper($code)])) {
                    return $code;
                }
            }
            return 'RS-A' . (count($existing) + 1);
        }
        if ($class === 'orc' || $class === 'irc') {
            $pair = strlen($rowOrPair) >= 2 ? substr($rowOrPair, 0, 2) : ($rowOrPair . 'B');
            $prefix = strtoupper($class) . '-' . $pair . '.';
            $n = 1;
            while (isset($existing[strtoupper($prefix . $n)])) {
                $n++;
            }
            return $prefix . $n;
        }
        return 'PATH-' . (count($existing) + 1);
    }

    /**
     * @return array<string,true> uppercased codes present in room
     */
    public static function pathCodesInRoom(int $roomId): array
    {
        $out = [];
        if ($roomId < 1) {
            return $out;
        }
        try {
            $rows = Database::fetchAll(
                'SELECT path_code, name FROM cable_paths WHERE room_id = ?',
                [$roomId]
            );
        } catch (Throwable $e) {
            return $out;
        }
        foreach ($rows as $r) {
            $c = trim((string)($r['path_code'] ?? ''));
            if ($c === '') {
                $c = trim((string)($r['name'] ?? ''));
            }
            if ($c !== '') {
                $out[strtoupper($c)] = true;
            }
        }
        return $out;
    }

    public static function validatePathCode(string $code): bool
    {
        $code = trim($code);
        if ($code === '' || strlen($code) > 40) {
            return false;
        }
        return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,39}$/', $code);
    }

    /** @return array<string,string> */
    public static function mediaClasses(): array
    {
        return [
            'copper' => 'Copper (Cat / DAC)',
            'fiber' => 'Fiber only',
            'mixed' => 'Mixed copper + fiber',
            'power' => 'Power only',
        ];
    }

    /** @return array<string,string> */
    public static function feedModes(): array
    {
        return [
            'overhead' => 'Feeds cabinets from overhead',
            'underfloor' => 'Feeds cabinets from underfloor (raised floor)',
            'both' => 'Both / mixed drops',
            'horizontal' => 'Horizontal only (no cabinet drop)',
        ];
    }

    /** Default map color for a media class (fiber trough yellow, etc.). */
    public static function defaultColorForMediaClass(string $class): string
    {
        return match (strtolower($class)) {
            'fiber' => '#eab308',
            'copper' => '#2563eb',
            'power' => '#111827',
            default => '#38bdf8',
        };
    }

    /**
     * Normalize waypoints JSON to list of {x,y,z?,corner?,radius_m?} in meters.
     * @param mixed $raw string JSON or array
     * @return list<array<string,mixed>>
     */
    public static function parseWaypoints(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            try {
                $raw = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                return [];
            }
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $pt) {
            if (!is_array($pt)) {
                continue;
            }
            if (!isset($pt['x'], $pt['y']) && !(isset($pt[0], $pt[1]))) {
                continue;
            }
            $x = (float)($pt['x'] ?? $pt[0] ?? 0);
            $y = (float)($pt['y'] ?? $pt[1] ?? 0);
            $row = ['x' => $x, 'y' => $y];
            if (isset($pt['z']) || isset($pt[2])) {
                $row['z'] = (float)($pt['z'] ?? $pt[2]);
            }
            $corner = strtolower(trim((string)($pt['corner'] ?? 'sharp')));
            if ($corner === 'fillet' || $corner === 'curve' || $corner === 'curved') {
                $row['corner'] = 'fillet';
                $r = isset($pt['radius_m']) ? (float)$pt['radius_m'] : self::DEFAULT_FILLET_RADIUS_M;
                $row['radius_m'] = max(0.15, min(1.5, $r > 0 ? $r : self::DEFAULT_FILLET_RADIUS_M));
            } else {
                $row['corner'] = 'sharp';
            }
            $out[] = $row;
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $points */
    public static function encodeWaypoints(array $points): string
    {
        $clean = [];
        $n = count($points);
        foreach ($points as $i => $pt) {
            if (!is_array($pt)) {
                continue;
            }
            $row = [
                'x' => round((float)($pt['x'] ?? 0), 4),
                'y' => round((float)($pt['y'] ?? 0), 4),
            ];
            if (isset($pt['z'])) {
                $row['z'] = round((float)$pt['z'], 4);
            }
            // Endpoints always sharp
            $isEnd = ($i === 0 || $i === $n - 1);
            $corner = strtolower(trim((string)($pt['corner'] ?? 'sharp')));
            if (!$isEnd && ($corner === 'fillet' || $corner === 'curve' || $corner === 'curved')) {
                $row['corner'] = 'fillet';
                $r = isset($pt['radius_m']) ? (float)$pt['radius_m'] : self::DEFAULT_FILLET_RADIUS_M;
                // Allow small radii (symmetric pull); hard-stop sharp is corner=sharp
                $row['radius_m'] = max(0.05, min(1.5, $r > 0 ? $r : self::DEFAULT_FILLET_RADIUS_M));
            }
            $clean[] = $row;
        }
        return json_encode($clean, JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /**
     * Paths for a room with waypoints decoded (for floorplan API).
     * @return list<array<string,mixed>>
     */
    public static function pathsForRoom(int $roomId, bool $activeOnly = true): array
    {
        if ($roomId < 1) {
            return [];
        }
        try {
            $sql = 'SELECT * FROM cable_paths WHERE room_id = ?';
            if ($activeOnly) {
                // is_active may not exist on very old DBs — fall back
                try {
                    $sql .= ' AND (is_active IS NULL OR is_active = 1)';
                } catch (Throwable $e) {
                }
            }
            $sql .= ' ORDER BY name';
            $rows = Database::fetchAll($sql, [$roomId]);
        } catch (Throwable $e) {
            try {
                $rows = Database::fetchAll(
                    'SELECT * FROM cable_paths WHERE room_id = ? ORDER BY name',
                    [$roomId]
                );
            } catch (Throwable $e2) {
                return [];
            }
        }
        foreach ($rows as &$r) {
            $r['waypoints_list'] = self::parseWaypoints($r['waypoints'] ?? null);
            $r['point_count'] = count($r['waypoints_list']);
            // Normalize DECIMAL strings so JS Number() / 3D elev resolve correctly
            if (isset($r['elevation_m']) && $r['elevation_m'] !== null && $r['elevation_m'] !== '') {
                $r['elevation_m'] = (float)$r['elevation_m'];
            }
            if (isset($r['width_m']) && $r['width_m'] !== null && $r['width_m'] !== '') {
                $r['width_m'] = (float)$r['width_m'];
            }
        }
        unset($r);
        return $rows;
    }

    /**
     * Persist path fields from form/API (shared by cables.php and floorplan).
     *
     * @param array<string,mixed> $data
     * @return array{ok:bool,path_id:int,message:string}
     */
    public static function savePath(array $data, ?int $pathId = null): array
    {
        $pathCode = trim((string)($data['path_code'] ?? ''));
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '' && $pathCode !== '') {
            $name = $pathCode;
        }
        if ($name === '') {
            return ['ok' => false, 'path_id' => 0, 'message' => 'Pathway code or name is required.'];
        }
        if ($pathCode !== '' && !self::validatePathCode($pathCode)) {
            return ['ok' => false, 'path_id' => 0, 'message' => 'Invalid pathway code (use letters, numbers, . _ -).'];
        }
        $roomId = isset($data['room_id']) && $data['room_id'] !== '' && $data['room_id'] !== null
            ? (int)$data['room_id'] : null;

        $segClass = strtolower(trim((string)($data['segment_class'] ?? '')));
        if ($segClass !== '' && !isset(self::segmentClasses()[$segClass])) {
            $segClass = 'custom';
        }
        if ($segClass === '') {
            $segClass = null;
        }

        // Uniqueness of path_code within room
        if ($pathCode !== '' && $roomId) {
            $existing = self::pathCodesInRoom($roomId);
            $up = strtoupper($pathCode);
            // Allow same code on self when updating
            $conflict = isset($existing[$up]);
            if ($conflict && $pathId) {
                try {
                    $self = Database::fetchOne(
                        'SELECT path_code FROM cable_paths WHERE path_id = ?',
                        [$pathId]
                    );
                    if ($self && strtoupper(trim((string)($self['path_code'] ?? ''))) === $up) {
                        $conflict = false;
                    }
                } catch (Throwable $e) {
                }
            }
            if ($conflict) {
                return ['ok' => false, 'path_id' => 0, 'message' => "Pathway code {$pathCode} already used in this room."];
            }
        }

        $mediaClass = strtolower(trim((string)($data['media_class'] ?? 'mixed')));
        if (!isset(self::mediaClasses()[$mediaClass])) {
            $mediaClass = 'mixed';
        }
        $pathKind = self::normalizePathKind((string)($data['path_kind'] ?? $data['path_type'] ?? 'ladder'));
        $feed = strtolower(trim((string)($data['feed_to'] ?? 'overhead')));
        if (!isset(self::feedModes()[$feed])) {
            $feed = $pathKind === 'underfloor' ? 'underfloor' : 'overhead';
        }
        $color = trim((string)($data['color_hex'] ?? ''));
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $color = self::defaultColorForPathKind($pathKind);
            if ($mediaClass === 'fiber' && $pathKind === 'ladder') {
                $color = self::defaultColorForMediaClass($mediaClass);
            }
        }
        $waypoints = null;
        if (array_key_exists('waypoints', $data)) {
            $pts = is_array($data['waypoints'])
                ? $data['waypoints']
                : self::parseWaypoints($data['waypoints']);
            $waypoints = self::encodeWaypoints($pts);
        } elseif (array_key_exists('waypoints_list', $data) && is_array($data['waypoints_list'])) {
            $waypoints = self::encodeWaypoints($data['waypoints_list']);
        }

        // Legacy path_type column kept in sync for old queries
        $pathType = match ($feed) {
            'underfloor' => 'underfloor',
            'both' => 'overhead',
            default => 'overhead',
        };
        if ($pathKind === 'conduit') {
            $pathType = 'conduit';
        } elseif (in_array($pathKind, ['ladder', 'tray', 'raceway', 'fiber_raceway', 'fiber_trough', 'fiber_u_channel'], true)) {
            $pathType = $feed === 'underfloor' ? 'underfloor' : 'tray';
        }

        $fields = [
            'name' => $name,
            'room_id' => $roomId,
            'path_type' => $pathType,
            'color_hex' => $color,
            'notes' => isset($data['notes']) && trim((string)$data['notes']) !== ''
                ? trim((string)$data['notes']) : null,
            'media_class' => $mediaClass,
            'path_kind' => $pathKind,
            'feed_to' => $feed,
            'is_active' => array_key_exists('is_active', $data)
                ? (!empty($data['is_active']) ? 1 : 0) : 1,
            'path_code' => $pathCode !== '' ? $pathCode : null,
            'segment_class' => $segClass,
        ];
        if ($waypoints !== null) {
            $fields['waypoints'] = $waypoints;
        }
        // Width: explicit value, or default on create
        if (array_key_exists('width_m', $data) && $data['width_m'] !== '' && $data['width_m'] !== null) {
            $fields['width_m'] = max(0.03, min(5.0, (float)$data['width_m']));
        } elseif (!$pathId) {
            $fields['width_m'] = self::defaultWidthForKind($pathKind);
        }
        // Elevation AFF (m): explicit, or default from feed on create
        if (array_key_exists('elevation_m', $data) && $data['elevation_m'] !== '' && $data['elevation_m'] !== null) {
            $fields['elevation_m'] = max(-2.0, min(12.0, (float)$data['elevation_m']));
        } elseif (!$pathId) {
            $fields['elevation_m'] = self::defaultElevationForFeed($feed);
        }

        try {
            if ($pathId && $pathId > 0) {
                Database::update('cable_paths', $fields, 'path_id = :id', [':id' => $pathId]);
                return ['ok' => true, 'path_id' => $pathId, 'message' => 'Path updated.'];
            }
            $id = Database::insert('cable_paths', $fields);
            return ['ok' => true, 'path_id' => (int)$id, 'message' => 'Path created.'];
        } catch (Throwable $e) {
            App::log('CablePlantService::savePath full: ' . $e->getMessage(), 'warning');
            // Retry with core columns; still try to keep elev/kind/width when present
            try {
                $basic = [
                    'name' => $fields['name'],
                    'room_id' => $fields['room_id'],
                    'path_type' => $fields['path_type'],
                    'color_hex' => $fields['color_hex'],
                    'notes' => $fields['notes'],
                ];
                foreach (['media_class', 'path_kind', 'feed_to', 'path_code', 'segment_class', 'width_m', 'elevation_m', 'is_active'] as $extra) {
                    if (array_key_exists($extra, $fields)) {
                        $basic[$extra] = $fields[$extra];
                    }
                }
                if ($waypoints !== null) {
                    $basic['waypoints'] = $waypoints;
                }
                if ($pathId && $pathId > 0) {
                    Database::update('cable_paths', $basic, 'path_id = :id', [':id' => $pathId]);
                    return ['ok' => true, 'path_id' => $pathId, 'message' => 'Path updated.'];
                }
                $id = Database::insert('cable_paths', $basic);
                return ['ok' => true, 'path_id' => (int)$id, 'message' => 'Path created.'];
            } catch (Throwable $e2) {
                // Last resort: absolute minimum columns
                try {
                    $min = [
                        'name' => $fields['name'],
                        'room_id' => $fields['room_id'],
                        'path_type' => $fields['path_type'] ?? 'tray',
                        'color_hex' => $fields['color_hex'] ?? '#38bdf8',
                    ];
                    if ($waypoints !== null) {
                        $min['waypoints'] = $waypoints;
                    }
                    if ($pathId && $pathId > 0) {
                        Database::update('cable_paths', $min, 'path_id = :id', [':id' => $pathId]);
                        $id = $pathId;
                    } else {
                        $id = (int)Database::insert('cable_paths', $min);
                    }
                    // Best-effort elev/kind after minimal insert
                    try {
                        $patch = [];
                        foreach (['path_kind', 'media_class', 'elevation_m', 'width_m', 'feed_to', 'path_code'] as $k) {
                            if (array_key_exists($k, $fields)) {
                                $patch[$k] = $fields[$k];
                            }
                        }
                        if ($patch !== []) {
                            Database::update('cable_paths', $patch, 'path_id = :id', [':id' => $id]);
                        }
                    } catch (Throwable $e3) {
                    }
                    return ['ok' => true, 'path_id' => (int)$id, 'message' => 'Path saved (compat mode).'];
                } catch (Throwable $e4) {
                    App::log('CablePlantService::savePath: ' . $e4->getMessage(), 'error');
                    return ['ok' => false, 'path_id' => 0, 'message' => $e4->getMessage()];
                }
            }
        }
    }

    /**
     * Set each fiber U-channel elevation to matching ladder elev + offset (default +10 in).
     * Match by path_code: F-RS-A ↔ RS-A, or notes "Cloned from …".
     *
     * @return array{ok:bool,message:string,updated:int}
     */
    public static function reapplyUChannelElevations(int $roomId, float $offsetM = self::DEFAULT_U_CHANNEL_ELEV_OFFSET_M): array
    {
        if ($roomId < 1) {
            return ['ok' => false, 'message' => 'Room required.', 'updated' => 0];
        }
        $paths = self::pathsForRoom($roomId, true);
        $laddersByCode = [];
        foreach ($paths as $p) {
            $k = self::normalizePathKind((string)($p['path_kind'] ?? ''));
            if (!in_array($k, ['ladder', 'tray'], true)) {
                continue;
            }
            $code = strtoupper(trim((string)($p['path_code'] ?? $p['name'] ?? '')));
            if ($code !== '') {
                $laddersByCode[$code] = $p;
            }
        }
        $updated = 0;
        foreach ($paths as $p) {
            $k = self::normalizePathKind((string)($p['path_kind'] ?? ''));
            if ($k !== 'fiber_u_channel') {
                continue;
            }
            $code = strtoupper(trim((string)($p['path_code'] ?? $p['name'] ?? '')));
            $src = null;
            // F-RS-A or FRS-A → RS-A
            if ($code !== '' && str_starts_with($code, 'F-') && isset($laddersByCode[substr($code, 2)])) {
                $src = $laddersByCode[substr($code, 2)];
            } elseif ($code !== '' && str_starts_with($code, 'F') && isset($laddersByCode[substr($code, 1)])) {
                $src = $laddersByCode[substr($code, 1)];
            } else {
                // notes: Cloned from RS-A (path_id N)
                $notes = (string)($p['notes'] ?? '');
                if (preg_match('/Cloned from\s+([^\s(]+)/i', $notes, $m)) {
                    $sc = strtoupper(trim($m[1]));
                    if (isset($laddersByCode[$sc])) {
                        $src = $laddersByCode[$sc];
                    }
                }
            }
            $srcElev = self::defaultElevationForFeed((string)($p['feed_to'] ?? 'overhead'));
            if ($src) {
                if (isset($src['elevation_m']) && $src['elevation_m'] !== null && $src['elevation_m'] !== '') {
                    $srcElev = (float)$src['elevation_m'];
                }
            }
            $newElev = max(-2.0, min(12.0, $srcElev + $offsetM));
            try {
                Database::update(
                    'cable_paths',
                    ['elevation_m' => $newElev],
                    'path_id = :id',
                    [':id' => (int)$p['path_id']]
                );
                $updated++;
            } catch (Throwable $e) {
                // continue
            }
        }
        return [
            'ok' => $updated > 0,
            'message' => $updated > 0
                ? ("Set elevation on {$updated} U-channel path(s) to ladder + "
                    . number_format($offsetM, 3) . ' m (~10″).')
                : 'No fiber U-channel paths found to update in this room.',
            'updated' => $updated,
        ];
    }

    public const MERGE_ENDPOINT_SNAP_M = 0.35;
    public const MERGE_ANGLE_TOL_DEG = 40.0; // how close to 90° for a “corner” merge

    /**
     * Merge two raceways at endpoints into one continuous path (junction becomes an interior corner).
     * Cables on the absorbed path are reassigned to the kept path.
     *
     * @param int $pathIdKeep Path that remains (geometry replaced by merged polyline)
     * @param int $endKeep 0 = start of keep, 1 = end of keep
     * @param int $pathIdOther Path that will be deleted after merge
     * @param int $endOther 0 = start of other, 1 = end of other
     * @return array{ok:bool,message:string,path_id:int,junction_index:int}
     */
    public static function mergePathsAtEndpoints(
        int $pathIdKeep,
        int $endKeep,
        int $pathIdOther,
        int $endOther
    ): array {
        if ($pathIdKeep < 1 || $pathIdOther < 1 || $pathIdKeep === $pathIdOther) {
            return ['ok' => false, 'message' => 'Select two different raceways.', 'path_id' => 0, 'junction_index' => -1];
        }
        $endKeep = $endKeep ? 1 : 0;
        $endOther = $endOther ? 1 : 0;

        try {
            $keep = Database::fetchOne('SELECT * FROM cable_paths WHERE path_id = ?', [$pathIdKeep]);
            $other = Database::fetchOne('SELECT * FROM cable_paths WHERE path_id = ?', [$pathIdOther]);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'path_id' => 0, 'junction_index' => -1];
        }
        if (!$keep || !$other) {
            return ['ok' => false, 'message' => 'Path not found.', 'path_id' => 0, 'junction_index' => -1];
        }
        if ((int)($keep['room_id'] ?? 0) !== (int)($other['room_id'] ?? 0)) {
            return ['ok' => false, 'message' => 'Both raceways must be in the same room.', 'path_id' => 0, 'junction_index' => -1];
        }

        $a = self::parseWaypoints($keep['waypoints'] ?? null);
        $b = self::parseWaypoints($other['waypoints'] ?? null);
        if (count($a) < 2 || count($b) < 2) {
            return ['ok' => false, 'message' => 'Each raceway needs at least two points.', 'path_id' => 0, 'junction_index' => -1];
        }

        // Orient so we always append other after keep: keep ends at junction, other starts at junction
        if ($endKeep === 0) {
            $a = array_reverse($a);
        }
        if ($endOther === 1) {
            $b = array_reverse($b);
        }

        $ja = $a[count($a) - 1];
        $jb = $b[0];
        $dist = hypot((float)$ja['x'] - (float)$jb['x'], (float)$ja['y'] - (float)$jb['y']);
        if ($dist > self::MERGE_ENDPOINT_SNAP_M * 2.5) {
            return [
                'ok' => false,
                'message' => 'Endpoints are too far apart to merge (move them closer first).',
                'path_id' => 0,
                'junction_index' => -1,
            ];
        }

        // Inbound direction at keep end and outbound at other start → angle at junction
        $prev = $a[count($a) - 2];
        $next = $b[1];
        $v1x = (float)$prev['x'] - (float)$ja['x'];
        $v1y = (float)$prev['y'] - (float)$ja['y'];
        $v2x = (float)$next['x'] - (float)$jb['x'];
        $v2y = (float)$next['y'] - (float)$jb['y'];
        $l1 = hypot($v1x, $v1y);
        $l2 = hypot($v2x, $v2y);
        if ($l1 < 1e-4 || $l2 < 1e-4) {
            return ['ok' => false, 'message' => 'Invalid geometry near endpoints.', 'path_id' => 0, 'junction_index' => -1];
        }
        $dot = max(-1.0, min(1.0, ($v1x * $v2x + $v1y * $v2y) / ($l1 * $l2)));
        $angleDeg = acos($dot) * (180.0 / M_PI);
        // Angle between directions from junction back along keep and out along other
        // For L-shape we want ~90° between the two legs
        if ($angleDeg < 90.0 - self::MERGE_ANGLE_TOL_DEG || $angleDeg > 90.0 + self::MERGE_ANGLE_TOL_DEG) {
            // Still allow merge but warn via message; many installs want join even if not exact 90
            // Strict-ish: allow 50–130°
            if ($angleDeg < 50.0 || $angleDeg > 130.0) {
                return [
                    'ok' => false,
                    'message' => 'Endpoints meet at ~' . round($angleDeg) . '° — need roughly 90° for a corner merge.',
                    'path_id' => 0,
                    'junction_index' => -1,
                ];
            }
        }

        // Junction point: midpoint (symmetric), sharp until user pulls curve
        $jx = ((float)$ja['x'] + (float)$jb['x']) / 2.0;
        $jy = ((float)$ja['y'] + (float)$jb['y']) / 2.0;
        $junction = ['x' => $jx, 'y' => $jy, 'corner' => 'sharp'];

        $merged = [];
        for ($i = 0, $n = count($a) - 1; $i < $n; $i++) {
            $merged[] = $a[$i];
        }
        $junctionIndex = count($merged);
        $merged[] = $junction;
        for ($i = 1, $n = count($b); $i < $n; $i++) {
            $merged[] = $b[$i];
        }

        $fields = [
            'waypoints' => self::encodeWaypoints($merged),
            // Prefer keep identity; note merge in notes lightly if empty
        ];
        try {
            Database::update('cable_paths', $fields, 'path_id = :id', [':id' => $pathIdKeep]);
            // Reassign cables from other → keep
            Database::query('UPDATE cables SET path_id = ? WHERE path_id = ?', [$pathIdKeep, $pathIdOther]);
            Database::delete('cable_paths', 'path_id = ?', [$pathIdOther]);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'path_id' => 0, 'junction_index' => -1];
        }

        $codeKeep = trim((string)($keep['path_code'] ?? $keep['name'] ?? ''));
        $codeOther = trim((string)($other['path_code'] ?? $other['name'] ?? ''));
        return [
            'ok' => true,
            'message' => 'Merged ' . ($codeOther !== '' ? $codeOther : '#' . $pathIdOther)
                . ' into ' . ($codeKeep !== '' ? $codeKeep : '#' . $pathIdKeep)
                . '. Drag the yellow diamond into the junction for a smooth 90° bend.',
            'path_id' => $pathIdKeep,
            'junction_index' => $junctionIndex,
        ];
    }

    /**
     * Undo a merge: split one polyline at an interior vertex into two paths.
     * Keeps the original path_id (and cable links) on the first piece.
     *
     * @return array{ok:bool,message:string,path_id:int,new_path_id:int,path?:array<string,mixed>,new_path?:array<string,mixed>}
     */
    public static function splitPathAtVertex(int $pathId, int $vertexIndex): array
    {
        if ($pathId < 1) {
            return ['ok' => false, 'message' => 'Invalid path.', 'path_id' => 0, 'new_path_id' => 0];
        }
        try {
            $src = Database::fetchOne('SELECT * FROM cable_paths WHERE path_id = ?', [$pathId]);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'path_id' => 0, 'new_path_id' => 0];
        }
        if (!$src) {
            return ['ok' => false, 'message' => 'Path not found.', 'path_id' => 0, 'new_path_id' => 0];
        }
        $pts = self::parseWaypoints($src['waypoints'] ?? null);
        $n = count($pts);
        if ($n < 3) {
            return [
                'ok' => false,
                'message' => 'Need at least three vertices to split (two legs).',
                'path_id' => $pathId,
                'new_path_id' => 0,
            ];
        }
        if ($vertexIndex < 1 || $vertexIndex > $n - 2) {
            return [
                'ok' => false,
                'message' => 'Click an interior corner (not an endpoint) to split there.',
                'path_id' => $pathId,
                'new_path_id' => 0,
            ];
        }

        $keepPts = array_slice($pts, 0, $vertexIndex + 1);
        $newPts = array_slice($pts, $vertexIndex);
        if (count($keepPts) < 2 || count($newPts) < 2) {
            return ['ok' => false, 'message' => 'Split would leave a path with fewer than two points.', 'path_id' => $pathId, 'new_path_id' => 0];
        }

        $srcCode = trim((string)($src['path_code'] ?? $src['name'] ?? ''));
        $newCode = self::uniquePathCodeInRoom((int)($src['room_id'] ?? 0), ($srcCode !== '' ? $srcCode : 'PATH') . '.2');

        $newData = [
            'room_id' => (int)($src['room_id'] ?? 0),
            'path_code' => $newCode,
            'name' => $newCode,
            'path_kind' => $src['path_kind'] ?? 'ladder',
            'media_class' => $src['media_class'] ?? 'mixed',
            'feed_to' => $src['feed_to'] ?? 'overhead',
            'color_hex' => $src['color_hex'] ?? '#38bdf8',
            'width_m' => $src['width_m'] ?? null,
            'elevation_m' => $src['elevation_m'] ?? null,
            'segment_class' => $src['segment_class'] ?? null,
            'waypoints_list' => $newPts,
            'notes' => 'Split from ' . ($srcCode !== '' ? $srcCode : ('#' . $pathId))
                . ' at vertex ' . ($vertexIndex + 1),
            'is_active' => 1,
        ];
        $created = self::savePath($newData, null);
        if (empty($created['ok'])) {
            return [
                'ok' => false,
                'message' => $created['message'] ?? 'Could not create the second path.',
                'path_id' => $pathId,
                'new_path_id' => 0,
            ];
        }
        $newId = (int)$created['path_id'];

        try {
            Database::update(
                'cable_paths',
                ['waypoints' => self::encodeWaypoints($keepPts)],
                'path_id = :id',
                [':id' => $pathId]
            );
        } catch (Throwable $e) {
            try {
                Database::delete('cable_paths', 'path_id = ?', [$newId]);
            } catch (Throwable $e2) {
            }
            return ['ok' => false, 'message' => $e->getMessage(), 'path_id' => $pathId, 'new_path_id' => 0];
        }

        $keepRow = Database::fetchOne('SELECT * FROM cable_paths WHERE path_id = ?', [$pathId]);
        $newRow = Database::fetchOne('SELECT * FROM cable_paths WHERE path_id = ?', [$newId]);
        if ($keepRow) {
            $keepRow['waypoints_list'] = self::parseWaypoints($keepRow['waypoints'] ?? null);
        }
        if ($newRow) {
            $newRow['waypoints_list'] = self::parseWaypoints($newRow['waypoints'] ?? null);
        }

        return [
            'ok' => true,
            'message' => 'Split ' . ($srcCode !== '' ? $srcCode : ('#' . $pathId))
                . ' at corner #' . ($vertexIndex + 1)
                . '. Original path kept (cables still attached). New path: ' . $newCode . '.',
            'path_id' => $pathId,
            'new_path_id' => $newId,
            'path' => $keepRow ?: null,
            'new_path' => $newRow ?: null,
        ];
    }

    /**
     * Find other-path endpoint within snap of a given path endpoint.
     *
     * @return array{path_id:int,end:int,distance:float,angle_deg:float}|null
     */
    public static function findMergeCandidate(int $pathId, int $end): ?array
    {
        $end = $end ? 1 : 0;
        try {
            $self = Database::fetchOne('SELECT * FROM cable_paths WHERE path_id = ?', [$pathId]);
        } catch (Throwable $e) {
            return null;
        }
        if (!$self) {
            return null;
        }
        $roomId = (int)($self['room_id'] ?? 0);
        $pts = self::parseWaypoints($self['waypoints'] ?? null);
        if (count($pts) < 2 || $roomId < 1) {
            return null;
        }
        $ep = $end === 0 ? $pts[0] : $pts[count($pts) - 1];
        $prev = $end === 0 ? $pts[1] : $pts[count($pts) - 2];
        $vx = (float)$prev['x'] - (float)$ep['x'];
        $vy = (float)$prev['y'] - (float)$ep['y'];
        $vl = hypot($vx, $vy);
        if ($vl < 1e-4) {
            return null;
        }
        $vx /= $vl;
        $vy /= $vl;

        try {
            $others = Database::fetchAll(
                'SELECT * FROM cable_paths WHERE room_id = ? AND path_id <> ? AND (is_active IS NULL OR is_active = 1)',
                [$roomId, $pathId]
            );
        } catch (Throwable $e) {
            try {
                $others = Database::fetchAll(
                    'SELECT * FROM cable_paths WHERE room_id = ? AND path_id <> ?',
                    [$roomId, $pathId]
                );
            } catch (Throwable $e2) {
                return null;
            }
        }

        $best = null;
        $bestD = self::MERGE_ENDPOINT_SNAP_M;
        foreach ($others as $o) {
            $opts = self::parseWaypoints($o['waypoints'] ?? null);
            if (count($opts) < 2) {
                continue;
            }
            foreach ([0, 1] as $oe) {
                $op = $oe === 0 ? $opts[0] : $opts[count($opts) - 1];
                $on = $oe === 0 ? $opts[1] : $opts[count($opts) - 2];
                $d = hypot((float)$ep['x'] - (float)$op['x'], (float)$ep['y'] - (float)$op['y']);
                if ($d > $bestD) {
                    continue;
                }
                $wx = (float)$on['x'] - (float)$op['x'];
                $wy = (float)$on['y'] - (float)$op['y'];
                $wl = hypot($wx, $wy);
                if ($wl < 1e-4) {
                    continue;
                }
                $wx /= $wl;
                $wy /= $wl;
                // Directions from each endpoint along its path; for L-join, angle between -vx and wx ~ 90
                // From junction: along keep is toward prev = (prev-ep) already as v; along other is wx,wy
                $dot = max(-1.0, min(1.0, $vx * $wx + $vy * $wy));
                $angle = acos($dot) * (180.0 / M_PI);
                if ($angle < 50.0 || $angle > 130.0) {
                    continue;
                }
                $bestD = $d;
                $best = [
                    'path_id' => (int)$o['path_id'],
                    'end' => $oe,
                    'distance' => $d,
                    'angle_deg' => $angle,
                ];
            }
        }
        return $best;
    }

    /**
     * Delete a pathway. If cables reference it: either block or clear path_id when $force.
     *
     * @return array{ok:bool,message:string,unlinked:int}
     */
    public static function deletePath(int $pathId, bool $force = false): array
    {
        if ($pathId < 1) {
            return ['ok' => false, 'message' => 'Invalid path.', 'unlinked' => 0];
        }
        try {
            $row = Database::fetchOne('SELECT path_id, name, path_code FROM cable_paths WHERE path_id = ?', [$pathId]);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'unlinked' => 0];
        }
        if (!$row) {
            return ['ok' => false, 'message' => 'Path not found.', 'unlinked' => 0];
        }
        $inUse = 0;
        try {
            $inUse = (int)Database::fetchValue('SELECT COUNT(*) FROM cables WHERE path_id = ?', [$pathId]);
        } catch (Throwable $e) {
            $inUse = 0;
        }
        if ($inUse > 0 && !$force) {
            return [
                'ok' => false,
                'message' => "Path is used by {$inUse} cable(s). Reassign them first, or force-delete to clear the path link.",
                'unlinked' => 0,
            ];
        }
        $unlinked = 0;
        try {
            if ($inUse > 0 && $force) {
                Database::query('UPDATE cables SET path_id = NULL WHERE path_id = ?', [$pathId]);
                $unlinked = $inUse;
            }
            Database::delete('cable_paths', 'path_id = ?', [$pathId]);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'unlinked' => 0];
        }
        $label = trim((string)($row['path_code'] ?? '')) !== ''
            ? (string)$row['path_code']
            : (string)($row['name'] ?? ('#' . $pathId));
        return [
            'ok' => true,
            'message' => 'Deleted pathway ' . $label
                . ($unlinked > 0 ? " (unlinked {$unlinked} cable(s))" : '') . '.',
            'unlinked' => $unlinked,
        ];
    }

    /**
     * Normalize cable row fields from POST/API.
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function cableFieldsFromInput(array $post): array
    {
        $media = trim((string)($post['media_type'] ?? ''));
        $speed = trim((string)($post['speed'] ?? ''));
        $colorHex = trim((string)($post['color_hex'] ?? ''));
        if ($colorHex === '' || !preg_match('/^#[0-9A-Fa-f]{6}$/', $colorHex)) {
            // Derive from speed or media preset
            $speeds = self::speedColors();
            if ($speed !== '' && isset($speeds[$speed])) {
                $colorHex = $speeds[$speed];
            } else {
                foreach (self::mediaPresets() as $p) {
                    if (strcasecmp($p['value'], $media) === 0) {
                        $colorHex = $p['default_color'];
                        break;
                    }
                }
            }
            if ($colorHex === '' || !preg_match('/^#[0-9A-Fa-f]{6}$/', $colorHex)) {
                $colorHex = '#38bdf8';
            }
        }
        $role = strtolower(trim((string)($post['cable_role'] ?? 'patch')));
        if (!isset(self::cableRoles()[$role])) {
            $role = 'patch';
        }
        $legacyColor = trim((string)($post['color'] ?? ''));
        if ($legacyColor === '' && $colorHex !== '') {
            $legacyColor = $colorHex;
        }

        $fields = [
            'cable_label' => ($t = trim((string)($post['cable_label'] ?? ''))) !== '' ? $t : null,
            'media_type' => $media !== '' ? $media : null,
            'length_m' => isset($post['length_m']) && $post['length_m'] !== ''
                ? (float)$post['length_m'] : null,
            'color' => $legacyColor !== '' ? $legacyColor : null,
            'color_hex' => $colorHex,
            'speed' => $speed !== '' ? $speed : null,
            'cable_role' => $role,
            'circuit_id' => ($c = trim((string)($post['circuit_id'] ?? ''))) !== '' ? $c : null,
            'strand_count' => isset($post['strand_count']) && $post['strand_count'] !== ''
                ? max(1, (int)$post['strand_count']) : null,
            'a_port_id' => isset($post['a_port_id']) && $post['a_port_id'] !== ''
                ? (int)$post['a_port_id'] : null,
            'b_port_id' => isset($post['b_port_id']) && $post['b_port_id'] !== ''
                ? (int)$post['b_port_id'] : null,
            'path_id' => isset($post['path_id']) && $post['path_id'] !== ''
                ? (int)$post['path_id'] : null,
            'status' => in_array(($s = (string)($post['status'] ?? 'active')), ['active', 'planned', 'retired'], true)
                ? $s : 'active',
            'notes' => ($n = trim((string)($post['notes'] ?? ''))) !== '' ? $n : null,
        ];
        // Optional multi-hop path_ids[] / path_ids_ordered / path_route_json
        if (class_exists('CableRouteService')) {
            $ids = [];
            // Ordered CSV from "Calculate shortest path" (preserves hop sequence)
            if (!empty($post['path_ids_ordered']) && is_string($post['path_ids_ordered'])) {
                foreach (explode(',', $post['path_ids_ordered']) as $pid) {
                    $pid = (int)trim($pid);
                    if ($pid > 0) {
                        $ids[] = $pid;
                    }
                }
            } elseif (isset($post['path_ids']) && is_array($post['path_ids'])) {
                foreach ($post['path_ids'] as $pid) {
                    $pid = (int)$pid;
                    if ($pid > 0) {
                        $ids[] = $pid;
                    }
                }
            }
            if ($ids !== []) {
                $fields['path_id'] = $ids[0];
                $fields['path_route_json'] = CableRouteService::encodeRouteJson(
                    $ids,
                    (string)($post['route_source'] ?? (!empty($post['path_ids_ordered']) ? 'calculated' : 'manual'))
                );
            } elseif (isset($post['path_route_json']) && is_string($post['path_route_json']) && $post['path_route_json'] !== '') {
                $fields['path_route_json'] = $post['path_route_json'];
                $ids = CableRouteService::parseRouteJson($post['path_route_json']);
                if ($ids !== [] && empty($fields['path_id'])) {
                    $fields['path_id'] = $ids[0];
                }
            }
        }
        return $fields;
    }

    /**
     * Default data-port label + media when creating a device from a type/model.
     * Patch panels get jack numbers (01, 02, …); fiber models use LC.
     *
     * @return array{label:string,media_type:string,port_type:string,port_number:int}
     */
    public static function defaultDataPortFields(string $deviceType, ?string $model, int $n, int $total): array
    {
        $n = max(1, $n);
        $total = max($n, $total);
        $blob = strtolower(trim($deviceType . ' ' . (string)$model));
        $isPanel = $deviceType === 'patch_panel'
            || (bool)preg_match('/\bpatch(\s|-|_)*panel\b/', $blob);
        $fiber = (bool)preg_match('/\b(om[345]|os2|fiber|fibre|lc|sc|mpo|mtp)\b/', $blob);
        $width = $total >= 100 ? 3 : 2;
        $label = $isPanel
            ? str_pad((string)$n, $width, '0', STR_PAD_LEFT)
            : ('Eth' . $n);

        return [
            'port_type' => 'data',
            'port_number' => $n,
            'label' => $label,
            'media_type' => $fiber ? 'LC' : 'RJ45',
        ];
    }

    public static function insertDataPorts(
        int $deviceId,
        string $deviceType,
        ?string $model,
        int $count,
        ?string $mediaOverride = null
    ): int {
        $count = max(0, min(512, $count));
        $mediaOverride = $mediaOverride !== null ? trim($mediaOverride) : '';
        $created = 0;
        for ($i = 1; $i <= $count; $i++) {
            $fields = self::defaultDataPortFields($deviceType, $model, $i, $count);
            $fields['device_id'] = $deviceId;
            if ($mediaOverride !== '') {
                $fields['media_type'] = $mediaOverride;
            }
            Database::insert('device_ports', $fields);
            $created++;
        }
        return $created;
    }

    /**
     * Catalog starters: passive panels as devices (jack count = num_data_ports).
     *
     * @return list<array{model:string,device_type:string,u_height:int,num_data_ports:int,notes:string}>
     */
    public static function patchPanelTemplateSeeds(): array
    {
        return [
            [
                'model' => '24-port Cat6 patch panel',
                'device_type' => 'patch_panel',
                'u_height' => 1,
                'num_data_ports' => 24,
                'notes' => 'Passive 1U copper. Devices created from this template get jacks 01–24 (not Eth1).',
            ],
            [
                'model' => '48-port Cat6 patch panel',
                'device_type' => 'patch_panel',
                'u_height' => 2,
                'num_data_ports' => 48,
                'notes' => 'Passive 2U copper. Jacks 01–48.',
            ],
            [
                'model' => '24-port Cat6A patch panel',
                'device_type' => 'patch_panel',
                'u_height' => 1,
                'num_data_ports' => 24,
                'notes' => 'Passive 1U Cat6A copper. Jacks 01–24.',
            ],
            [
                'model' => '12-port LC duplex fiber panel',
                'device_type' => 'patch_panel',
                'u_height' => 1,
                'num_data_ports' => 12,
                'notes' => 'Passive 1U fiber (12 duplex LC). Ports 01–12, media LC.',
            ],
            [
                'model' => '24-port LC duplex fiber panel',
                'device_type' => 'patch_panel',
                'u_height' => 1,
                'num_data_ports' => 24,
                'notes' => 'Passive 1U fiber (24 duplex LC). Ports 01–24, media LC.',
            ],
        ];
    }

    /**
     * Insert missing patch-panel templates (matched by model + type).
     *
     * @return array{added:int,skipped:int}
     */
    public static function ensurePatchPanelTemplates(): array
    {
        $added = 0;
        $skipped = 0;
        foreach (self::patchPanelTemplateSeeds() as $seed) {
            $exists = Database::fetchValue(
                'SELECT TOP 1 template_id FROM device_templates
                 WHERE model = ? AND device_type = ? AND is_active = 1',
                [$seed['model'], 'patch_panel']
            );
            if ($exists) {
                $skipped++;
                continue;
            }
            $row = [
                'manufacturer_id' => null,
                'model' => $seed['model'],
                'device_type' => 'patch_panel',
                'u_height' => (int)$seed['u_height'],
                'weight_kg' => null,
                'watts' => null,
                'num_power_ports' => 0,
                'num_data_ports' => (int)$seed['num_data_ports'],
                'notes' => $seed['notes'],
                'is_active' => 1,
            ];
            Database::insert('device_templates', $row);
            $added++;
        }
        return ['added' => $added, 'skipped' => $skipped];
    }

    /** @return list<string> */
    public static function csvImportHeaders(): array
    {
        return [
            'cable_label', 'cable_role', 'a_device', 'a_port', 'b_device', 'b_port',
            'a_cabinet', 'b_cabinet', 'media_type', 'speed', 'circuit_id', 'path_code',
            'length_m', 'strand_count', 'notes', 'color_hex',
        ];
    }

    /** @return list<string> */
    public static function csvImportExampleRow(): array
    {
        return [
            'PP-A-01-12', 'structured', 'PP-A-01', '12', 'SW-01', 'Eth12',
            'Cab-A', 'Cab-A', 'Cat6', '1G', 'IDF-A-12', 'RS-A',
            '15.0', '', 'Permanent link from panel to switch', '#2563eb',
        ];
    }

    /**
     * Bulk-create cables from CSV text. Resolves devices by label (optional cabinet)
     * and ports by label or number. Does not require every site port in the form.
     *
     * @param array{skip_existing?:bool,max_rows?:int} $options
     * @return array{ok:bool,created:int,skipped:int,errors:list<string>,message:string}
     */
    public static function importConnectionsCsv(string $text, array $options = []): array
    {
        $skipExisting = !array_key_exists('skip_existing', $options) || !empty($options['skip_existing']);
        $maxRows = max(1, min(5000, (int)($options['max_rows'] ?? 2000)));
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);
        if (trim($text) === '') {
            return [
                'ok' => false, 'created' => 0, 'skipped' => 0,
                'errors' => ['File is empty.'],
                'message' => 'File is empty.',
            ];
        }

        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return [
                'ok' => false, 'created' => 0, 'skipped' => 0,
                'errors' => ['Could not read CSV.'],
                'message' => 'Could not read CSV.',
            ];
        }
        fwrite($fh, $text);
        rewind($fh);

        $headerLine = fgetcsv($fh);
        while (is_array($headerLine) && self::csvRowIsComment($headerLine)) {
            $headerLine = fgetcsv($fh);
        }
        if (!is_array($headerLine) || $headerLine === []) {
            fclose($fh);
            return [
                'ok' => false, 'created' => 0, 'skipped' => 0,
                'errors' => ['Missing header row.'],
                'message' => 'Missing header row.',
            ];
        }

        $map = [];
        foreach ($headerLine as $i => $raw) {
            $key = self::csvHeaderKey((string)$raw);
            if ($key !== '') {
                $map[$key] = (int)$i;
            }
        }
        $needOne = isset($map['a_device']) || isset($map['a_end']) || isset($map['a_port']);
        if (!$needOne || (!isset($map['b_device']) && !isset($map['b_end']) && !isset($map['b_port']))) {
            fclose($fh);
            return [
                'ok' => false, 'created' => 0, 'skipped' => 0,
                'errors' => ['CSV needs a_device/a_port (or a_end) and b_device/b_port (or b_end) columns.'],
                'message' => 'CSV headers do not match the import template.',
            ];
        }

        $created = 0;
        $skipped = 0;
        $errors = [];
        $lineNo = 1;
        $dataRows = 0;
        while (($row = fgetcsv($fh)) !== false) {
            $lineNo++;
            if (!is_array($row) || self::csvRowIsComment($row) || self::csvRowIsEmpty($row)) {
                continue;
            }
            $dataRows++;
            if ($dataRows > $maxRows) {
                $errors[] = 'Stopped after ' . $maxRows . ' data rows (import limit).';
                break;
            }
            try {
                $cell = static function (string $key) use ($map, $row): string {
                    if (!isset($map[$key])) {
                        return '';
                    }
                    $i = $map[$key];
                    return trim((string)($row[$i] ?? ''));
                };

                $aDevice = $cell('a_device');
                $aPort = $cell('a_port');
                $bDevice = $cell('b_device');
                $bPort = $cell('b_port');
                if ($aDevice === '' || $aPort === '') {
                    [$ad, $ap] = self::splitEndLabel($cell('a_end'));
                    if ($aDevice === '') {
                        $aDevice = $ad;
                    }
                    if ($aPort === '') {
                        $aPort = $ap;
                    }
                }
                if ($bDevice === '' || $bPort === '') {
                    [$bd, $bp] = self::splitEndLabel($cell('b_end'));
                    if ($bDevice === '') {
                        $bDevice = $bd;
                    }
                    if ($bPort === '') {
                        $bPort = $bp;
                    }
                }
                if ($aDevice === '' || $aPort === '' || $bDevice === '' || $bPort === '') {
                    throw new RuntimeException('Need A and B device + port.');
                }

                $aDev = self::findDeviceForImport($aDevice, $cell('a_cabinet'));
                $bDev = self::findDeviceForImport($bDevice, $cell('b_cabinet'));
                $aP = self::findPortForImport((int)$aDev['device_id'], $aPort);
                $bP = self::findPortForImport((int)$bDev['device_id'], $bPort);
                $aId = (int)$aP['port_id'];
                $bId = (int)$bP['port_id'];
                if ($aId === $bId) {
                    throw new RuntimeException('A and B ports are the same.');
                }

                $existing = Database::fetchValue(
                    'SELECT TOP 1 cable_id FROM cables
                     WHERE (a_port_id = ? AND b_port_id = ?) OR (a_port_id = ? AND b_port_id = ?)',
                    [$aId, $bId, $bId, $aId]
                );
                if ($existing) {
                    if ($skipExisting) {
                        $skipped++;
                        continue;
                    }
                    throw new RuntimeException('Connection already exists (cable #' . (int)$existing . ').');
                }

                $post = [
                    'cable_label' => $cell('cable_label'),
                    'cable_role' => $cell('cable_role') !== '' ? $cell('cable_role') : 'structured',
                    'media_type' => $cell('media_type'),
                    'speed' => $cell('speed'),
                    'circuit_id' => $cell('circuit_id'),
                    'length_m' => $cell('length_m'),
                    'strand_count' => $cell('strand_count'),
                    'notes' => $cell('notes'),
                    'color_hex' => $cell('color_hex'),
                    'a_port_id' => $aId,
                    'b_port_id' => $bId,
                    'status' => 'active',
                ];
                $pathCode = $cell('path_code');
                if ($pathCode !== '') {
                    $ids = self::findPathIdsForImport($pathCode);
                    if ($ids !== []) {
                        $post['path_ids'] = $ids;
                        $post['path_ids_ordered'] = implode(',', $ids);
                    }
                }
                $fields = self::cableFieldsFromInput($post);
                $fields['installed_at'] = date('Y-m-d H:i:s');
                Database::insert('cables', $fields);
                $created++;
            } catch (Throwable $e) {
                $errors[] = 'Row ' . $lineNo . ': ' . $e->getMessage();
            }
        }
        fclose($fh);

        $errN = count($errors);
        $ok = $created > 0 || ($dataRows > 0 && $errN === 0);
        $parts = [];
        $parts[] = $created . ' imported';
        if ($skipped > 0) {
            $parts[] = $skipped . ' skipped (already exist)';
        }
        if ($errN > 0) {
            $parts[] = $errN . ' error' . ($errN === 1 ? '' : 's');
        }
        if ($dataRows === 0) {
            $message = 'No data rows in the CSV.';
            $ok = false;
        } else {
            $message = 'CSV: ' . implode(', ', $parts) . '.';
        }

        return [
            'ok' => $ok,
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
            'message' => $message,
        ];
    }

    /** @param list<string|null> $row */
    private static function csvRowIsComment(array $row): bool
    {
        $first = trim((string)($row[0] ?? ''));
        return str_starts_with($first, '#');
    }

    /** @param list<string|null> $row */
    private static function csvRowIsEmpty(array $row): bool
    {
        foreach ($row as $c) {
            if (trim((string)$c) !== '') {
                return false;
            }
        }
        return true;
    }

    private static function csvHeaderKey(string $raw): string
    {
        $h = strtolower(trim($raw));
        $h = preg_replace('/[^a-z0-9]+/', '_', $h) ?? $h;
        $h = trim($h, '_');
        $aliases = [
            'label' => 'cable_label',
            'role' => 'cable_role',
            'a_end' => 'a_end',
            'b_end' => 'b_end',
            'a_end_device' => 'a_device',
            'b_end_device' => 'b_device',
            'a_end_port' => 'a_port',
            'b_end_port' => 'b_port',
            'media' => 'media_type',
            'path' => 'path_code',
            'pathway' => 'path_code',
            'raceway' => 'path_code',
            'circuit' => 'circuit_id',
            'length' => 'length_m',
            'color' => 'color_hex',
            'strands' => 'strand_count',
        ];
        return $aliases[$h] ?? $h;
    }

    /** @return array{0:string,1:string} device, port */
    private static function splitEndLabel(string $end): array
    {
        $end = trim($end);
        if ($end === '') {
            return ['', ''];
        }
        foreach ([' / ', ' | ', '/'] as $sep) {
            $pos = strrpos($end, $sep);
            if ($pos !== false) {
                return [trim(substr($end, 0, $pos)), trim(substr($end, $pos + strlen($sep)))];
            }
        }
        return [$end, ''];
    }

    /**
     * @return array<string,mixed>
     */
    private static function findDeviceForImport(string $label, string $cabinet): array
    {
        $sql = 'SELECT d.device_id, d.label, d.cabinet_id, c.name AS cabinet_name
                FROM devices d
                LEFT JOIN cabinets c ON c.cabinet_id = d.cabinet_id
                WHERE d.is_active = 1 AND LOWER(LTRIM(RTRIM(d.label))) = LOWER(?)';
        $params = [$label];
        if ($cabinet !== '') {
            $sql .= ' AND LOWER(LTRIM(RTRIM(ISNULL(c.name, \'\')))) = LOWER(?)';
            $params[] = $cabinet;
        }
        $rows = Database::fetchAll($sql, $params);
        if (count($rows) === 1) {
            return $rows[0];
        }
        if (count($rows) > 1) {
            throw new RuntimeException(
                'Device "' . $label . '" is ambiguous'
                . ($cabinet === '' ? ' — add a_cabinet / b_cabinet.' : '.')
            );
        }
        throw new RuntimeException(
            'Device "' . $label . '" not found'
            . ($cabinet !== '' ? (' in cabinet "' . $cabinet . '"') : '') . '.'
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function findPortForImport(int $deviceId, string $spec): array
    {
        $spec = trim($spec);
        if ($spec === '' || $deviceId < 1) {
            throw new RuntimeException('Port is required.');
        }
        $row = Database::fetchOne(
            'SELECT * FROM device_ports
             WHERE device_id = ? AND LOWER(LTRIM(RTRIM(ISNULL(label, \'\')))) = LOWER(?)',
            [$deviceId, $spec]
        );
        if ($row) {
            return $row;
        }
        $num = null;
        if (preg_match('/^(?:eth|gi|te|fo|port|p|jack)?\s*0*(\d+)$/i', $spec, $m)) {
            $num = (int)$m[1];
        } elseif (ctype_digit($spec)) {
            $num = (int)$spec;
        }
        if ($num !== null && $num > 0) {
            $row = Database::fetchOne(
                'SELECT * FROM device_ports WHERE device_id = ? AND port_number = ? AND port_type = \'data\'',
                [$deviceId, $num]
            );
            if ($row) {
                return $row;
            }
            $padded = str_pad((string)$num, 2, '0', STR_PAD_LEFT);
            $row = Database::fetchOne(
                'SELECT * FROM device_ports WHERE device_id = ? AND (
                    label IN (?, ?, ?, ?)
                 )',
                [$deviceId, (string)$num, $padded, 'Eth' . $num, 'Port ' . $num]
            );
            if ($row) {
                return $row;
            }
        }
        throw new RuntimeException('Port "' . $spec . '" not found on that device.');
    }

    /** @return list<int> */
    private static function findPathIdsForImport(string $codes): array
    {
        $parts = preg_split('/\s*(?:→|->|>|,|;)\s*/u', $codes) ?: [];
        $ids = [];
        foreach ($parts as $code) {
            $code = trim((string)$code);
            if ($code === '') {
                continue;
            }
            $row = Database::fetchOne(
                'SELECT path_id FROM cable_paths
                 WHERE LOWER(LTRIM(RTRIM(ISNULL(path_code, \'\')))) = LOWER(?)
                    OR LOWER(LTRIM(RTRIM(ISNULL(name, \'\')))) = LOWER(?)',
                [$code, $code]
            );
            if (!$row) {
                throw new RuntimeException('Raceway "' . $code . '" not found.');
            }
            $ids[] = (int)$row['path_id'];
        }
        return $ids;
    }
}
