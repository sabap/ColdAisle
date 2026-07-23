<?php
/**
 * Attach device + template image URLs to cabinets for 3D rack texturing.
 */
declare(strict_types=1);

class Cabinet3dData
{
    /**
     * @param list<array<string,mixed>> $cabinets
     * @return list<array<string,mixed>>
     */
    public static function withDevices(array $cabinets): array
    {
        if ($cabinets === []) {
            return [];
        }

        $ids = [];
        foreach ($cabinets as $c) {
            $id = (int)($c['cabinet_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return $cabinets;
        }

        // Embed validated ints — ODBC binding of IN (?) lists can type-clash on SQL Server
        $idList = implode(',', array_map('intval', $ids));
        $devices = Database::fetchAll(
            "SELECT d.device_id, d.cabinet_id, d.label, d.position_u, d.u_height,
                    d.half_depth, d.back_side, d.device_type,
                    t.front_picture, t.rear_picture
             FROM devices d
             LEFT JOIN device_templates t ON t.template_id = d.template_id
             WHERE d.is_active = 1
               AND d.position_u IS NOT NULL
               AND d.cabinet_id IN ({$idList})
             ORDER BY d.cabinet_id, d.position_u"
        );

        $byCab = [];
        foreach ($devices as $d) {
            $cid = (int)$d['cabinet_id'];
            $byCab[$cid][] = [
                'device_id' => (int)$d['device_id'],
                'label' => $d['label'] ?? '',
                'position_u' => (int)$d['position_u'],
                'u_height' => max(1, (int)$d['u_height']),
                'half_depth' => !empty($d['half_depth']) ? 1 : 0,
                'back_side' => !empty($d['back_side']) ? 1 : 0,
                'device_type' => $d['device_type'] ?? 'server',
                'front_image' => self::mediaUrl($d['front_picture'] ?? null),
                'rear_image' => self::mediaUrl($d['rear_picture'] ?? null),
            ];
        }

        foreach ($cabinets as &$c) {
            $cid = (int)($c['cabinet_id'] ?? 0);
            $c['devices'] = $byCab[$cid] ?? [];
            // ensure numeric types for JS
            $c['u_height'] = (int)($c['u_height'] ?? 42);
            $c['width_mm'] = (int)($c['width_mm'] ?? 600);
            $c['depth_mm'] = (int)($c['depth_mm'] ?? 1200);
            $c['pos_x'] = (float)($c['pos_x'] ?? 0);
            $c['pos_y'] = (float)($c['pos_y'] ?? 0);
            $c['rotation_deg'] = (float)($c['rotation_deg'] ?? 0);
            if (isset($c['u_used'])) {
                $c['u_used'] = (int)$c['u_used'];
            }
        }
        unset($c);

        return $cabinets;
    }

    private static function mediaUrl(?string $rel): ?string
    {
        if ($rel === null || trim($rel) === '') {
            return null;
        }
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        return App::url('media.php?f=' . rawurlencode($rel));
    }
}
