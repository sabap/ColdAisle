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

    /**
     * Raceway construction type (3D-ready). Primary: ladder, fiber_raceway, conduit.
     * @return array<string,string>
     */
    public static function pathKinds(): array
    {
        return [
            'ladder' => 'Ladder tray',
            'fiber_raceway' => 'Fiber raceway',
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

    /** Primary kinds shown in finish UI. @return list<string> */
    public static function primaryPathKinds(): array
    {
        return ['ladder', 'fiber_raceway', 'conduit'];
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
            'fiber_raceway', 'fiber_trough' => '#eab308',
            'ladder', 'tray' => '#2563eb',
            'conduit' => '#64748b',
            'busway' => '#111827',
            default => '#38bdf8',
        };
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
                $row['radius_m'] = max(0.15, min(1.5, $r > 0 ? $r : self::DEFAULT_FILLET_RADIUS_M));
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
        } elseif (in_array($pathKind, ['ladder', 'tray', 'raceway', 'fiber_raceway', 'fiber_trough'], true)) {
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
        if (isset($data['width_m']) && $data['width_m'] !== '' && $data['width_m'] !== null) {
            $fields['width_m'] = max(0.05, min(5.0, (float)$data['width_m']));
        }

        try {
            if ($pathId && $pathId > 0) {
                Database::update('cable_paths', $fields, 'path_id = :id', [':id' => $pathId]);
                return ['ok' => true, 'path_id' => $pathId, 'message' => 'Path updated.'];
            }
            $id = Database::insert('cable_paths', $fields);
            return ['ok' => true, 'path_id' => (int)$id, 'message' => 'Path created.'];
        } catch (Throwable $e) {
            // Retry without new columns if mid-upgrade
            try {
                $basic = [
                    'name' => $fields['name'],
                    'room_id' => $fields['room_id'],
                    'path_type' => $fields['path_type'],
                    'color_hex' => $fields['color_hex'],
                    'notes' => $fields['notes'],
                ];
                if ($waypoints !== null) {
                    $basic['waypoints'] = $waypoints;
                }
                if ($pathId && $pathId > 0) {
                    Database::update('cable_paths', $basic, 'path_id = :id', [':id' => $pathId]);
                    return ['ok' => true, 'path_id' => $pathId, 'message' => 'Path updated (basic columns).'];
                }
                $id = Database::insert('cable_paths', $basic);
                return ['ok' => true, 'path_id' => (int)$id, 'message' => 'Path created (basic columns).'];
            } catch (Throwable $e2) {
                App::log('CablePlantService::savePath: ' . $e2->getMessage(), 'error');
                return ['ok' => false, 'path_id' => 0, 'message' => $e2->getMessage()];
            }
        }
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

        return [
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
    }
}
