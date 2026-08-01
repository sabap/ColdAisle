<?php
/**
 * Resolve env sensors to 3D floor positions for heat spheres.
 * Prefer explicit pos_*; else cabinet footprint + placement offset (intake / aisle).
 */
declare(strict_types=1);

class EnvSensor3dData
{
    /** Default heat influence radius (meters) — ~6 ft */
    public const DEFAULT_RADIUS_M = 1.83;

    /**
     * Sensors with last temperature that can be placed in a room.
     *
     * @return list<array{
     *   sensor_id:int,name:string,sensor_kind:string,placement:?string,
     *   temp:?float,humidity:?float,unit:?string,
     *   pos_x:float,pos_y:float,pos_z:float,
     *   radius_m:float,derived:bool,cabinet_id:?int,cabinet_name:?string
     * }>
     */
    public static function forFloor(float $radiusM = self::DEFAULT_RADIUS_M, ?int $roomId = null): array
    {
        $rows = self::loadSensorRows($roomId);
        if ($rows === []) {
            return [];
        }

        $out = [];
        $skipped = 0;
        foreach ($rows as $r) {
            $placed = self::resolvePosition($r);
            if ($placed === null) {
                $skipped++;
                continue;
            }
            $temp = self::toFloat($r['last_value'] ?? null);
            if ($temp === null) {
                $skipped++;
                continue;
            }
            // Only temperature-ish kinds drive heat color
            $kind = (string)($r['sensor_kind'] ?? 'temperature');
            if (in_array($kind, ['humidity', 'leak', 'airflow', 'differential_pressure'], true)) {
                continue;
            }
            $hum = self::toFloat($r['last_humidity'] ?? null);

            $out[] = [
                'sensor_id' => (int)$r['sensor_id'],
                'name' => (string)$r['name'],
                'sensor_kind' => $kind,
                'placement' => $r['placement'] !== null && $r['placement'] !== ''
                    ? (string)$r['placement']
                    : null,
                'temp' => round($temp, 2),
                'humidity' => $hum !== null ? round($hum, 1) : null,
                'unit' => $r['unit'] !== null && $r['unit'] !== '' ? (string)$r['unit'] : '°C',
                'pos_x' => round($placed['x'], 3),
                'pos_y' => round($placed['y'], 3),
                'pos_z' => round($placed['z'], 3),
                'radius_m' => $radiusM,
                'derived' => $placed['derived'],
                'cabinet_id' => $placed['cabinet_id'],
                'cabinet_name' => $placed['cabinet_name'],
            ];
        }

        if ($out === [] && $skipped > 0) {
            App::log(
                'EnvSensor3dData: 0 placeable of ' . count($rows)
                . ' sensors with values (need cabinet on floor plan with pos_x/pos_y)',
                'info'
            );
        }

        return $out;
    }

    /**
     * Diagnostic summary for UI.
     *
     * @return array{placeable:int,with_value:int,no_cabinet:int,cabinet_unplaced:int}
     */
    public static function diagnostics(?int $roomId = null): array
    {
        $rows = self::loadSensorRows($roomId);
        $placeable = 0;
        $noCabinet = 0;
        $unplaced = 0;
        foreach ($rows as $r) {
            $p = self::resolvePosition($r);
            if ($p !== null) {
                $placeable++;
                continue;
            }
            $cid = self::cabinetIdFromRow($r);
            if ($cid < 1) {
                $noCabinet++;
            } else {
                $unplaced++;
            }
        }
        return [
            'placeable' => $placeable,
            'with_value' => count($rows),
            'no_cabinet' => $noCabinet,
            'cabinet_unplaced' => $unplaced,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function loadSensorRows(?int $roomId): array
    {
        // Avoid fragile columns (last_humidity / front_facing) that break the whole query
        $sql = "SELECT s.sensor_id, s.name, s.sensor_kind, s.placement, s.host_type,
                       s.location_label, s.last_value, s.unit, s.last_seen_at,
                       s.pos_x AS s_pos_x, s.pos_y AS s_pos_y, s.pos_z AS s_pos_z,
                       s.cabinet_id AS s_cabinet_id, s.device_id,
                       d.cabinet_id AS d_cabinet_id, d.position_u, d.u_height, d.label AS device_label,
                       d.device_type AS device_type,
                       c.cabinet_id AS c_cabinet_id, c.name AS cabinet_name,
                       c.pos_x AS c_pos_x, c.pos_y AS c_pos_y, c.pos_z AS c_pos_z,
                       c.rotation_deg, c.width_mm, c.depth_mm, c.u_height AS c_u_height,
                       c.room_id AS c_room_id
                FROM env_sensors s
                LEFT JOIN devices d ON d.device_id = s.device_id AND d.is_active = 1
                LEFT JOIN cabinets c ON c.cabinet_id = COALESCE(s.cabinet_id, d.cabinet_id)
                     AND c.is_active = 1
                WHERE s.is_active = 1
                  AND s.last_value IS NOT NULL";
        $params = [];
        if ($roomId !== null && $roomId > 0) {
            $sql .= ' AND (c.room_id = ? OR (c.room_id IS NULL AND s.cabinet_id IS NOT NULL))';
            $params[] = $roomId;
        }
        $sql .= ' ORDER BY s.name';

        try {
            $rows = Database::fetchAll($sql, $params);
        } catch (Throwable $e) {
            App::log('EnvSensor3dData load: ' . $e->getMessage(), 'warning');
            return [];
        }

        // Optional humidity column
        try {
            $hum = Database::fetchAll(
                'SELECT sensor_id, last_humidity FROM env_sensors WHERE is_active = 1 AND last_humidity IS NOT NULL'
            );
            $byId = [];
            foreach ($hum as $h) {
                $byId[(int)$h['sensor_id']] = $h['last_humidity'];
            }
            foreach ($rows as &$r) {
                $id = (int)$r['sensor_id'];
                $r['last_humidity'] = $byId[$id] ?? null;
            }
            unset($r);
        } catch (Throwable $e) {
            foreach ($rows as &$r) {
                $r['last_humidity'] = null;
            }
            unset($r);
        }

        return $rows;
    }

    private static function cabinetIdFromRow(array $r): int
    {
        foreach (['c_cabinet_id', 'cabinet_id', 's_cabinet_id', 'd_cabinet_id'] as $k) {
            if (isset($r[$k]) && (int)$r[$k] > 0) {
                return (int)$r[$k];
            }
        }
        return 0;
    }

    private static function toFloat($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return (float)$v;
        }
        return null;
    }

    /**
     * @param array<string,mixed> $r
     * @return array{x:float,y:float,z:float,derived:bool,cabinet_id:?int,cabinet_name:?string}|null
     */
    public static function resolvePosition(array $r): ?array
    {
        // Explicit sensor coordinates win
        if (self::toFloat($r['s_pos_x'] ?? null) !== null
            && self::toFloat($r['s_pos_y'] ?? null) !== null
        ) {
            $z = self::toFloat($r['s_pos_z'] ?? null);
            return [
                'x' => (float)$r['s_pos_x'],
                'y' => (float)$r['s_pos_y'],
                'z' => $z !== null ? $z : 1.0,
                'derived' => false,
                'cabinet_id' => self::cabinetIdFromRow($r) ?: null,
                'cabinet_name' => isset($r['cabinet_name']) ? (string)$r['cabinet_name'] : null,
            ];
        }

        $name = (string)($r['name'] ?? '');
        $thMod = null;
        $thPort = null;
        if (preg_match('/\bTH\s*0*(\d+)\s*:?\s*(\d+)\b/i', $name, $tm)) {
            $thMod = (int)$tm[1];
            $thPort = (int)$tm[2];
        } elseif (preg_match('/\bTH\s*0*(\d+)\b/i', $name, $tm)) {
            $thMod = (int)$tm[1];
        }
        $isTh = $thMod !== null;
        $isMm = (bool)preg_match('/\bMM\s*:?\s*\d+/i', $name);

        $cab = null;

        // 1) TH sensors: ALWAYS resolve expansion rack — never fall back to MM host cabinet
        if ($isTh && $thMod !== null) {
            $cab = self::findExpansionCabinet($thMod);
            if (!$cab) {
                $cab = self::findCabinetByLocationLabel(
                    (string)($r['location_label'] ?? $r['placement'] ?? '')
                );
            }
            if (!$cab) {
                // Do not stack on MM — unplaceable until expansion rack is known
                App::log(
                    'EnvSensor3dData: no floor cabinet for expansion TH'
                    . sprintf('%02d', $thMod) . ' (' . $name . ')',
                    'info'
                );
                return null;
            }
        }

        // 2) MM / other: host device or sensor cabinet
        if (!$cab) {
            $cid = self::cabinetIdFromRow($r);
            // Host device cabinet if sensor cabinet empty
            if ($cid < 1 && !empty($r['device_id'])) {
                try {
                    $devCab = Database::fetchOne(
                        'SELECT cabinet_id FROM devices WHERE device_id = ? AND is_active = 1',
                        [(int)$r['device_id']]
                    );
                    if ($devCab && (int)($devCab['cabinet_id'] ?? 0) > 0) {
                        $cid = (int)$devCab['cabinet_id'];
                    }
                } catch (Throwable $e) {
                    // ignore
                }
            }
            if ($cid < 1) {
                return null;
            }
            if (self::toFloat($r['c_pos_x'] ?? null) !== null
                && self::toFloat($r['c_pos_y'] ?? null) !== null
                && (int)($r['c_cabinet_id'] ?? $r['cabinet_id'] ?? 0) === $cid
            ) {
                $cab = [
                    'cabinet_id' => $cid,
                    'cabinet_name' => $r['cabinet_name'] ?? null,
                    'c_pos_x' => $r['c_pos_x'],
                    'c_pos_y' => $r['c_pos_y'],
                    'c_pos_z' => $r['c_pos_z'] ?? null,
                    'rotation_deg' => $r['rotation_deg'] ?? 0,
                    'width_mm' => $r['width_mm'] ?? 600,
                    'depth_mm' => $r['depth_mm'] ?? 1200,
                    'c_u_height' => $r['c_u_height'] ?? 42,
                ];
            } else {
                $cab = self::loadCabinet($cid);
            }
        }

        if (!$cab || self::toFloat($cab['c_pos_x'] ?? null) === null
            || self::toFloat($cab['c_pos_y'] ?? null) === null
        ) {
            return null;
        }

        $w = max(0.4, ((float)($cab['width_mm'] ?? 600)) / 1000.0);
        $d = max(0.6, ((float)($cab['depth_mm'] ?? 1200)) / 1000.0);
        $uH = max(1, (int)($cab['c_u_height'] ?? $cab['u_height'] ?? 42));
        $rackH = $uH * 0.04445;
        $cx = (float)$cab['c_pos_x'] + $w / 2.0;
        $cy = (float)$cab['c_pos_y'] + $d / 2.0;
        $rot = ((float)($cab['rotation_deg'] ?? 0)) * M_PI / 180.0;

        $fx = sin($rot);
        $fz = cos($rot);
        // Lateral (along rack face) for multi-port modules so spheres don't fully stack
        $lx = cos($rot);
        $lz = -sin($rot);

        $placement = strtolower(trim((string)($r['placement'] ?? 'equipment_intake')));
        $placement = str_replace([' ', '-'], '_', $placement);

        $offset = 0.45;
        $side = 1.0;

        switch ($placement) {
            case 'exhaust':
            case 'hot_aisle':
            case 'return_air':
                $side = -1.0;
                $offset = 0.50;
                break;
            case 'cold_aisle':
            case 'equipment_intake':
            case 'intake':
            case 'supply_air':
                $side = 1.0;
                $offset = 0.45;
                break;
            case 'underfloor':
                $side = 0.0;
                $offset = 0.0;
                break;
            case 'ambient':
            case 'other':
            default:
                $side = 1.0;
                $offset = 0.35;
                break;
        }

        $dist = ($d / 2.0) + $offset;
        $x = $cx + $fx * $side * $dist;
        $y = $cy + $fz * $side * $dist;

        // Spread ports along face: port 1 left … port 6 right (~12 cm steps)
        $port = $thPort;
        if ($port === null && preg_match('/\bMM\s*:?\s*(\d+)\b/i', $name, $mm)) {
            $port = (int)$mm[1];
        }
        if ($port !== null && $port >= 1) {
            $lat = (($port - 1) - 2.5) * 0.12; // center around mid-rack
            $x += $lx * $lat;
            $y += $lz * $lat;
        }

        $z = $rackH * 0.45;
        if ($placement === 'underfloor') {
            $z = 0.25;
        } elseif (!empty($r['position_u'])) {
            $posU = (int)$r['position_u'];
            $uHt = max(1, (int)($r['u_height'] ?? 1));
            $midU = $posU + ($uHt - 1) / 2.0;
            $z = max(0.2, min($rackH - 0.1, ($midU - 0.5) * 0.04445));
        } elseif ($port !== null) {
            // Slight vertical stagger by port so labels separate
            $z = max(0.4, min($rackH - 0.2, 0.5 + ($port - 1) * 0.12));
        }

        $cid = (int)($cab['cabinet_id'] ?? 0);

        return [
            'x' => $x,
            'y' => $y,
            'z' => $z,
            'derived' => true,
            'cabinet_id' => $cid > 0 ? $cid : null,
            'cabinet_name' => isset($cab['cabinet_name']) ? (string)$cab['cabinet_name'] : null,
        ];
    }

    /** @return array<string,mixed>|null */
    private static function loadCabinet(int $cabinetId): ?array
    {
        if ($cabinetId < 1) {
            return null;
        }
        try {
            $row = Database::fetchOne(
                'SELECT cabinet_id, name AS cabinet_name,
                        pos_x AS c_pos_x, pos_y AS c_pos_y, pos_z AS c_pos_z,
                        rotation_deg, width_mm, depth_mm, u_height AS c_u_height, room_id AS c_room_id
                 FROM cabinets
                 WHERE cabinet_id = ? AND is_active = 1',
                [$cabinetId]
            );
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Match location labels like Z2-RA-R5 / Z1-RB-R8 to a floor-placed cabinet name.
     *
     * @return array<string,mixed>|null
     */
    private static function findCabinetByLocationLabel(string $label): ?array
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }
        // Extract rack-ish tokens: RA, RB, R5, R8, ROW A, etc.
        $tokens = [];
        if (preg_match_all('/\bR[A-Z]\b|\bR\d+\b|\bROW\s*[A-Z]\b/i', $label, $m)) {
            foreach ($m[0] as $t) {
                $tokens[] = strtoupper(preg_replace('/\s+/', '', $t) ?? $t);
            }
        }
        if ($tokens === []) {
            // whole label as soft match
            $tokens[] = $label;
        }
        try {
            $cabs = Database::fetchAll(
                'SELECT cabinet_id, name AS cabinet_name,
                        pos_x AS c_pos_x, pos_y AS c_pos_y, pos_z AS c_pos_z,
                        rotation_deg, width_mm, depth_mm, u_height AS c_u_height, room_id AS c_room_id
                 FROM cabinets
                 WHERE is_active = 1 AND pos_x IS NOT NULL AND pos_y IS NOT NULL'
            );
        } catch (Throwable $e) {
            return null;
        }
        $best = null;
        $bestScore = 0;
        foreach ($cabs as $c) {
            $cn = strtoupper((string)($c['cabinet_name'] ?? ''));
            $score = 0;
            foreach ($tokens as $t) {
                $t = strtoupper((string)$t);
                if ($t !== '' && str_contains($cn, $t)) {
                    $score += 2;
                }
            }
            // Prefer names that look like rack IDs
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $c;
            }
        }
        return $bestScore >= 2 ? $best : null;
    }

    /**
     * Floor-placed cabinet for TH expansion module N.
     * Matches device labels like TH01, TH-EXP-ROWA [01], EXP 01, etc.
     *
     * @return array<string,mixed>|null
     */
    private static function findExpansionCabinet(int $moduleNum): ?array
    {
        if ($moduleNum < 1) {
            return null;
        }
        static $cache = [];
        if (array_key_exists($moduleNum, $cache)) {
            return $cache[$moduleNum];
        }

        $nn = sprintf('%02d', $moduleNum);
        $n = (string)$moduleNum;
        $patterns = [
            '%TH' . $nn . '%',
            '%TH-' . $nn . '%',
            '%TH ' . $nn . '%',
            '%TH' . $n . '%',
            '%[' . $nn . ']%',
            '%[ ' . $nn . ' ]%',
            '%[0' . $n . ']%',
            '%[' . $n . ']%',
            '%EXP%' . $nn . '%',
            '%EXP%' . $n . '%',
            '%EXPANSION%' . $nn . '%',
            '%ROW%' . $nn . '%',
        ];

        try {
            // All placed env modules / monitors — score in PHP for flexible matching
            $devs = Database::fetchAll(
                "SELECT d.device_id, d.label, d.model, d.device_type, d.cabinet_id,
                        c.cabinet_id AS cab_id, c.name AS cabinet_name,
                        c.pos_x AS c_pos_x, c.pos_y AS c_pos_y, c.pos_z AS c_pos_z,
                        c.rotation_deg, c.width_mm, c.depth_mm, c.u_height AS c_u_height, c.room_id AS c_room_id
                 FROM devices d
                 INNER JOIN cabinets c ON c.cabinet_id = d.cabinet_id AND c.is_active = 1
                 WHERE d.is_active = 1
                   AND c.pos_x IS NOT NULL AND c.pos_y IS NOT NULL
                   AND d.device_type IN ('env_module', 'env_monitor', 'other', 'chassis')
                 ORDER BY d.label"
            );
            $best = null;
            $bestScore = 0;
            foreach ($devs as $d) {
                $hay = strtoupper(
                    (string)($d['label'] ?? '') . ' ' . (string)($d['model'] ?? '')
                    . ' ' . (string)($d['cabinet_name'] ?? '')
                );
                $score = 0;
                $type = (string)($d['device_type'] ?? '');
                if ($type === 'env_module') {
                    $score += 5;
                }
                if (preg_match('/\bTH\s*0*' . $moduleNum . '\b/', $hay)
                    || preg_match('/\bTH0*' . $nn . '\b/', $hay)
                ) {
                    $score += 50;
                }
                if (str_contains($hay, '[' . $nn . ']') || str_contains($hay, '[0' . $n . ']')
                    || str_contains($hay, '[' . $n . ']')
                ) {
                    $score += 40;
                }
                if (str_contains($hay, 'EXP') && (
                    str_contains($hay, $nn) || preg_match('/\b0*' . $moduleNum . '\b/', $hay)
                )) {
                    $score += 25;
                }
                // Penalize pure management modules when looking for expansions
                if (str_contains($hay, 'MGR') || str_contains($hay, 'MANAG')
                    || str_contains($hay, 'AP9340') && $type === 'env_monitor'
                ) {
                    $score -= 30;
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = [
                        'cabinet_id' => (int)$d['cab_id'],
                        'cabinet_name' => $d['cabinet_name'] ?? null,
                        'c_pos_x' => $d['c_pos_x'],
                        'c_pos_y' => $d['c_pos_y'],
                        'c_pos_z' => $d['c_pos_z'],
                        'rotation_deg' => $d['rotation_deg'] ?? 0,
                        'width_mm' => $d['width_mm'] ?? 600,
                        'depth_mm' => $d['depth_mm'] ?? 1200,
                        'c_u_height' => $d['c_u_height'] ?? 42,
                        'c_room_id' => $d['c_room_id'] ?? null,
                    ];
                }
            }

            // Also score cabinets by name alone (device may be typed wrong)
            if ($bestScore < 20) {
                $cabs = Database::fetchAll(
                    'SELECT cabinet_id, name AS cabinet_name,
                            pos_x AS c_pos_x, pos_y AS c_pos_y, pos_z AS c_pos_z,
                            rotation_deg, width_mm, depth_mm, u_height AS c_u_height, room_id AS c_room_id
                     FROM cabinets
                     WHERE is_active = 1 AND pos_x IS NOT NULL AND pos_y IS NOT NULL'
                );
                foreach ($cabs as $c) {
                    $hay = strtoupper((string)($c['cabinet_name'] ?? ''));
                    $score = 0;
                    if (preg_match('/\bTH\s*0*' . $moduleNum . '\b/', $hay)) {
                        $score += 40;
                    }
                    if (str_contains($hay, '[' . $nn . ']') || str_contains($hay, 'EXP')) {
                        $score += 15;
                    }
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = $c;
                        $best['cabinet_id'] = (int)$c['cabinet_id'];
                    }
                }
            }

            // Ordered fallback: env_modules only (never MM)
            if ($bestScore < 20) {
                $mods = [];
                foreach ($devs as $d) {
                    if (($d['device_type'] ?? '') !== 'env_module') {
                        continue;
                    }
                    $hay = strtoupper((string)($d['label'] ?? ''));
                    if (str_contains($hay, 'MGR') || str_contains($hay, 'MANAG')) {
                        continue;
                    }
                    $mods[] = [
                        'cabinet_id' => (int)$d['cab_id'],
                        'cabinet_name' => $d['cabinet_name'] ?? null,
                        'c_pos_x' => $d['c_pos_x'],
                        'c_pos_y' => $d['c_pos_y'],
                        'c_pos_z' => $d['c_pos_z'],
                        'rotation_deg' => $d['rotation_deg'] ?? 0,
                        'width_mm' => $d['width_mm'] ?? 600,
                        'depth_mm' => $d['depth_mm'] ?? 1200,
                        'c_u_height' => $d['c_u_height'] ?? 42,
                    ];
                }
                // Sort by label already from SQL order of $devs — re-fetch sorted
                usort($mods, static function ($a, $b) {
                    return strcmp((string)($a['cabinet_name'] ?? ''), (string)($b['cabinet_name'] ?? ''));
                });
                if (isset($mods[$moduleNum - 1])) {
                    $best = $mods[$moduleNum - 1];
                    $bestScore = 20;
                }
            }

            $row = $bestScore >= 20 ? $best : null;
        } catch (Throwable $e) {
            App::log('findExpansionCabinet: ' . $e->getMessage(), 'warning');
            $row = null;
        }

        $cache[$moduleNum] = $row ?: null;
        return $cache[$moduleNum];
    }
}
