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
        try {
            $sql = "SELECT s.sensor_id, s.name, s.sensor_kind, s.placement, s.host_type,
                           s.last_value, s.last_humidity, s.unit, s.last_seen_at,
                           s.pos_x AS s_pos_x, s.pos_y AS s_pos_y, s.pos_z AS s_pos_z,
                           s.cabinet_id AS s_cabinet_id, s.device_id,
                           d.cabinet_id AS d_cabinet_id, d.position_u, d.u_height,
                           c.cabinet_id, c.name AS cabinet_name,
                           c.pos_x AS c_pos_x, c.pos_y AS c_pos_y, c.pos_z AS c_pos_z,
                           c.rotation_deg, c.width_mm, c.depth_mm, c.u_height AS c_u_height,
                           c.room_id AS c_room_id,
                           d.front_facing AS d_front_facing
                    FROM env_sensors s
                    LEFT JOIN devices d ON d.device_id = s.device_id AND d.is_active = 1
                    LEFT JOIN cabinets c ON c.cabinet_id = COALESCE(s.cabinet_id, d.cabinet_id)
                         AND c.is_active = 1
                    WHERE s.is_active = 1
                      AND s.last_value IS NOT NULL";
            $params = [];
            if ($roomId !== null && $roomId > 0) {
                $sql .= ' AND c.room_id = ?';
                $params[] = $roomId;
            }
            $sql .= ' ORDER BY s.name';
            $rows = Database::fetchAll($sql, $params);
        } catch (Throwable $e) {
            App::log('EnvSensor3dData: ' . $e->getMessage(), 'warning');
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $placed = self::resolvePosition($r);
            if ($placed === null) {
                continue;
            }
            $temp = is_numeric($r['last_value'] ?? null) ? (float)$r['last_value'] : null;
            if ($temp === null) {
                continue;
            }
            // Only temperature-ish kinds drive heat color
            $kind = (string)($r['sensor_kind'] ?? 'temperature');
            if ($kind === 'humidity' || $kind === 'leak' || $kind === 'airflow') {
                continue;
            }
            $hum = isset($r['last_humidity']) && $r['last_humidity'] !== null && $r['last_humidity'] !== ''
                ? (float)$r['last_humidity']
                : null;

            $out[] = [
                'sensor_id' => (int)$r['sensor_id'],
                'name' => (string)$r['name'],
                'sensor_kind' => $kind,
                'placement' => $r['placement'] !== null ? (string)$r['placement'] : null,
                'temp' => round($temp, 2),
                'humidity' => $hum !== null ? round($hum, 1) : null,
                'unit' => $r['unit'] !== null ? (string)$r['unit'] : '°C',
                'pos_x' => round($placed['x'], 3),
                'pos_y' => round($placed['y'], 3),
                'pos_z' => round($placed['z'], 3),
                'radius_m' => $radiusM,
                'derived' => $placed['derived'],
                'cabinet_id' => $placed['cabinet_id'],
                'cabinet_name' => $placed['cabinet_name'],
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $r
     * @return array{x:float,y:float,z:float,derived:bool,cabinet_id:?int,cabinet_name:?string}|null
     */
    public static function resolvePosition(array $r): ?array
    {
        // Explicit sensor coordinates win
        if ($r['s_pos_x'] !== null && $r['s_pos_x'] !== ''
            && $r['s_pos_y'] !== null && $r['s_pos_y'] !== ''
        ) {
            $z = ($r['s_pos_z'] !== null && $r['s_pos_z'] !== '')
                ? (float)$r['s_pos_z']
                : 1.0;
            return [
                'x' => (float)$r['s_pos_x'],
                'y' => (float)$r['s_pos_y'],
                'z' => $z,
                'derived' => false,
                'cabinet_id' => isset($r['cabinet_id']) ? (int)$r['cabinet_id'] : null,
                'cabinet_name' => isset($r['cabinet_name']) ? (string)$r['cabinet_name'] : null,
            ];
        }

        $cid = (int)($r['cabinet_id'] ?? 0);
        // Sensors hosted on the MM often still belong to a TH expansion rack — place by name
        $cabRow = null;
        if ($cid > 0 && $r['c_pos_x'] !== null && $r['c_pos_x'] !== ''
            && $r['c_pos_y'] !== null && $r['c_pos_y'] !== ''
        ) {
            $cabRow = $r;
        }
        $name = (string)($r['name'] ?? '');
        if (preg_match('/\bTH\s*0*(\d+)/i', $name, $tm)) {
            $mod = (int)$tm[1];
            $expCab = self::findExpansionCabinet($mod);
            if ($expCab) {
                $cabRow = array_merge($r, $expCab);
                $cid = (int)$expCab['cabinet_id'];
            }
        }
        if ($cid < 1 || !$cabRow) {
            return null;
        }
        // Cabinet must be on the floor plan
        if ($cabRow['c_pos_x'] === null || $cabRow['c_pos_x'] === ''
            || $cabRow['c_pos_y'] === null || $cabRow['c_pos_y'] === ''
        ) {
            return null;
        }
        $r = $cabRow;

        $w = max(0.4, ((float)($r['width_mm'] ?? 600)) / 1000.0);
        $d = max(0.6, ((float)($r['depth_mm'] ?? 1200)) / 1000.0);
        $uH = max(1, (int)($r['c_u_height'] ?? 42));
        $rackH = $uH * 0.04445;
        $cx = (float)$r['c_pos_x'] + $w / 2.0;
        $cy = (float)$r['c_pos_y'] + $d / 2.0; // plan Y → Three.js Z
        $rot = ((float)($r['rotation_deg'] ?? 0)) * M_PI / 180.0;

        // Front direction in plan (matches dcim-3d: local +Z is front face)
        $fx = sin($rot);
        $fz = cos($rot);

        $placement = strtolower((string)($r['placement'] ?? 'equipment_intake'));
        $offset = 0.45; // meters beyond cabinet face
        $side = 1.0; // +1 front, -1 rear

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

        // Face is at half-depth; then step into aisle
        $dist = ($d / 2.0) + $offset;
        $x = $cx + $fx * $side * $dist;
        $y = $cy + $fz * $side * $dist;

        // Height: mid of device U if known, else mid-rack; underfloor near floor
        $z = $rackH * 0.45;
        if ($placement === 'underfloor') {
            $z = 0.25;
        } elseif (!empty($r['position_u'])) {
            $posU = (int)$r['position_u'];
            $uHt = max(1, (int)($r['u_height'] ?? 1));
            $midU = $posU + ($uHt - 1) / 2.0;
            // U1 near floor: z grows with U from bottom
            $z = max(0.2, min($rackH - 0.1, ($midU - 0.5) * 0.04445));
        }

        return [
            'x' => $x,
            'y' => $y,
            'z' => $z,
            'derived' => true,
            'cabinet_id' => $cid,
            'cabinet_name' => isset($r['cabinet_name']) ? (string)$r['cabinet_name'] : null,
        ];
    }

    /**
     * Find floor-placed cabinet for TH expansion module N (env_module device).
     *
     * @return array<string,mixed>|null keys cabinet_id, cabinet_name, c_pos_*, width_mm, …
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
        $patterns = [
            '%TH' . sprintf('%02d', $moduleNum) . '%',
            '%TH' . $moduleNum . '%',
            '%[' . sprintf('%02d', $moduleNum) . ']%',
            '%[0' . $moduleNum . ']%',
            '%EXP%' . $moduleNum . '%',
        ];
        try {
            // Prefer env_module devices with TH in label
            $row = Database::fetchOne(
                "SELECT TOP 1 c.cabinet_id, c.name AS cabinet_name,
                        c.pos_x AS c_pos_x, c.pos_y AS c_pos_y, c.pos_z AS c_pos_z,
                        c.rotation_deg, c.width_mm, c.depth_mm, c.u_height AS c_u_height, c.room_id AS c_room_id
                 FROM devices d
                 INNER JOIN cabinets c ON c.cabinet_id = d.cabinet_id AND c.is_active = 1
                 WHERE d.is_active = 1
                   AND d.device_type IN ('env_module', 'env_monitor', 'other')
                   AND c.pos_x IS NOT NULL AND c.pos_y IS NOT NULL
                   AND (
                        d.label LIKE ?
                     OR d.label LIKE ?
                     OR d.label LIKE ?
                     OR d.model LIKE ?
                   )
                 ORDER BY CASE WHEN d.device_type = 'env_module' THEN 0 ELSE 1 END, d.label",
                [
                    $patterns[0],
                    $patterns[1],
                    $patterns[2],
                    $patterns[0],
                ]
            );
            if (!$row) {
                // Fallback: Nth env_module on floor by name sort
                $mods = Database::fetchAll(
                    "SELECT c.cabinet_id, c.name AS cabinet_name,
                            c.pos_x AS c_pos_x, c.pos_y AS c_pos_y, c.pos_z AS c_pos_z,
                            c.rotation_deg, c.width_mm, c.depth_mm, c.u_height AS c_u_height, c.room_id AS c_room_id
                     FROM devices d
                     INNER JOIN cabinets c ON c.cabinet_id = d.cabinet_id AND c.is_active = 1
                     WHERE d.is_active = 1 AND d.device_type = 'env_module'
                       AND c.pos_x IS NOT NULL AND c.pos_y IS NOT NULL
                     ORDER BY d.label"
                );
                $idx = $moduleNum - 1;
                $row = $mods[$idx] ?? null;
            }
        } catch (Throwable $e) {
            $row = null;
        }
        $cache[$moduleNum] = $row ?: null;
        return $cache[$moduleNum];
    }
}
