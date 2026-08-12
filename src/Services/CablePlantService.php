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

    /** @return array<string,string> */
    public static function pathKinds(): array
    {
        return [
            'tray' => 'Cable tray / ladder',
            'fiber_trough' => 'Fiber trough (e.g. yellow PVC)',
            'raceway' => 'Raceway / basket',
            'conduit' => 'Conduit',
            'underfloor' => 'Underfloor channel',
            'busway' => 'Busway / power path',
            'other' => 'Other',
        ];
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
     * Normalize waypoints JSON to list of {x,y,z?} in meters.
     * @param mixed $raw string JSON or array
     * @return list<array{x:float,y:float,z?:float}>
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
            $out[] = $row;
        }
        return $out;
    }

    /** @param list<array{x:float,y:float,z?:float}> $points */
    public static function encodeWaypoints(array $points): string
    {
        $clean = [];
        foreach ($points as $pt) {
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
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'path_id' => 0, 'message' => 'Path name is required.'];
        }
        $roomId = isset($data['room_id']) && $data['room_id'] !== '' && $data['room_id'] !== null
            ? (int)$data['room_id'] : null;
        $mediaClass = strtolower(trim((string)($data['media_class'] ?? 'mixed')));
        if (!isset(self::mediaClasses()[$mediaClass])) {
            $mediaClass = 'mixed';
        }
        $pathKind = strtolower(trim((string)($data['path_kind'] ?? $data['path_type'] ?? 'tray')));
        // Map legacy path_type values
        $legacyMap = [
            'overhead' => 'tray',
            'underfloor' => 'underfloor',
            'tray' => 'tray',
            'conduit' => 'conduit',
        ];
        if (isset($legacyMap[$pathKind])) {
            $pathKind = $legacyMap[$pathKind];
        }
        if (!isset(self::pathKinds()[$pathKind])) {
            $pathKind = 'tray';
        }
        $feed = strtolower(trim((string)($data['feed_to'] ?? 'overhead')));
        if (!isset(self::feedModes()[$feed])) {
            $feed = $pathKind === 'underfloor' ? 'underfloor' : 'overhead';
        }
        $color = trim((string)($data['color_hex'] ?? ''));
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $color = self::defaultColorForMediaClass($mediaClass);
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
            default => ($pathKind === 'underfloor' ? 'underfloor' : 'overhead'),
        };
        if ($pathKind === 'conduit') {
            $pathType = 'conduit';
        } elseif ($pathKind === 'tray' || $pathKind === 'raceway' || $pathKind === 'fiber_trough') {
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
