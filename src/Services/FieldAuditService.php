<?php
/**
 * Field audits: rack occupancy snapshot, photo store, last-audit vs live diff.
 */
declare(strict_types=1);

class FieldAuditService
{
    public const MAX_PHOTOS = 8;
    public const MAX_BYTES = 12_000_000;
    public const JPEG_MAX_EDGE = 1600;
    public const JPEG_QUALITY = 82;

    /**
     * Occupancy snapshot stored on each new cabinet audit.
     *
     * @return array{captured_at:string,cabinet_id:int,u_height:?int,devices:list<array<string,mixed>>}
     */
    public static function snapshotCabinet(int $cabinetId): array
    {
        $cab = Database::fetchOne(
            'SELECT cabinet_id, u_height FROM cabinets WHERE cabinet_id = ?',
            [$cabinetId]
        );
        $rows = Database::fetchAll(
            'SELECT device_id, label, device_type, position_u, u_height, back_side,
                    serial_no, asset_tag, status, parent_device_id
             FROM devices
             WHERE cabinet_id = ? AND is_active = 1 AND parent_device_id IS NULL
             ORDER BY position_u DESC',
            [$cabinetId]
        );
        $devices = [];
        foreach ($rows as $r) {
            $devices[] = [
                'device_id' => (int)$r['device_id'],
                'label' => (string)($r['label'] ?? ''),
                'device_type' => (string)($r['device_type'] ?? ''),
                'position_u' => $r['position_u'] !== null ? (int)$r['position_u'] : null,
                'u_height' => (int)($r['u_height'] ?? 1),
                'back_side' => !empty($r['back_side']),
                'serial_no' => (string)($r['serial_no'] ?? ''),
                'asset_tag' => (string)($r['asset_tag'] ?? ''),
                'status' => (string)($r['status'] ?? ''),
            ];
        }
        return [
            'captured_at' => date('c'),
            'cabinet_id' => $cabinetId,
            'u_height' => $cab['u_height'] !== null ? (int)$cab['u_height'] : null,
            'devices' => $devices,
        ];
    }

    /**
     * @return array{ok:bool,has_snapshot:bool,previous_at:?string,added:list,removed:list,moved:list,changed:list,same:int}
     */
    public static function diffCabinet(int $cabinetId): array
    {
        $empty = [
            'ok' => true,
            'has_snapshot' => false,
            'previous_at' => null,
            'added' => [],
            'removed' => [],
            'moved' => [],
            'changed' => [],
            'same' => 0,
        ];
        $prev = Database::fetchOne(
            'SELECT TOP 1 cabinet_audit_id, audited_at, snapshot_json
             FROM cabinet_audits
             WHERE cabinet_id = ? AND snapshot_json IS NOT NULL AND LTRIM(RTRIM(snapshot_json)) <> \'\'
             ORDER BY audited_at DESC',
            [$cabinetId]
        );
        if (!$prev) {
            return $empty;
        }
        $snap = json_decode((string)$prev['snapshot_json'], true);
        if (!is_array($snap) || !isset($snap['devices']) || !is_array($snap['devices'])) {
            return $empty;
        }

        $oldById = [];
        foreach ($snap['devices'] as $d) {
            if (!is_array($d)) {
                continue;
            }
            $id = (int)($d['device_id'] ?? 0);
            if ($id > 0) {
                $oldById[$id] = $d;
            }
        }
        $live = self::snapshotCabinet($cabinetId);
        $newById = [];
        foreach ($live['devices'] as $d) {
            $newById[(int)$d['device_id']] = $d;
        }

        $added = [];
        $removed = [];
        $moved = [];
        $changed = [];
        $same = 0;

        foreach ($newById as $id => $n) {
            if (!isset($oldById[$id])) {
                $added[] = self::diffRow($n, 'Added since last audit');
                continue;
            }
            $o = $oldById[$id];
            $notes = [];
            $ou = isset($o['position_u']) ? (int)$o['position_u'] : null;
            $nu = $n['position_u'];
            $oface = !empty($o['back_side']) ? 'rear' : 'front';
            $nface = !empty($n['back_side']) ? 'rear' : 'front';
            if ($ou !== $nu || $oface !== $nface) {
                $notes[] = 'Moved from U' . ($ou ?? '?') . ' ' . $oface . ' → U' . ($nu ?? '?') . ' ' . $nface;
            }
            if ((string)($o['label'] ?? '') !== (string)$n['label']) {
                $notes[] = 'Label was “' . (string)($o['label'] ?? '') . '”';
            }
            if ((string)($o['serial_no'] ?? '') !== (string)$n['serial_no']) {
                $notes[] = 'Serial changed';
            }
            if ((string)($o['status'] ?? '') !== (string)$n['status']) {
                $notes[] = 'Status ' . (string)($o['status'] ?? '?') . ' → ' . $n['status'];
            }
            if ($notes) {
                $row = self::diffRow($n, implode('; ', $notes));
                if (str_starts_with($notes[0], 'Moved')) {
                    $moved[] = $row;
                } else {
                    $changed[] = $row;
                }
            } else {
                $same++;
            }
        }
        foreach ($oldById as $id => $o) {
            if (!isset($newById[$id])) {
                $removed[] = self::diffRow($o, 'Present at last audit — gone from live inventory');
            }
        }

        return [
            'ok' => true,
            'has_snapshot' => true,
            'previous_at' => (string)$prev['audited_at'],
            'previous_audit_id' => (int)$prev['cabinet_audit_id'],
            'added' => $added,
            'removed' => $removed,
            'moved' => $moved,
            'changed' => $changed,
            'same' => $same,
            'change_count' => count($added) + count($removed) + count($moved) + count($changed),
        ];
    }

    /**
     * @param array<string,mixed> $d
     * @return array<string,mixed>
     */
    private static function diffRow(array $d, string $note): array
    {
        $face = !empty($d['back_side']) ? 'rear' : 'front';
        $u = isset($d['position_u']) && $d['position_u'] !== null && $d['position_u'] !== ''
            ? (int)$d['position_u']
            : null;
        return [
            'device_id' => (int)($d['device_id'] ?? 0),
            'label' => (string)($d['label'] ?? ''),
            'position_u' => $u,
            'u_height' => (int)($d['u_height'] ?? 1),
            'face' => $face,
            'note' => $note,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function photosForAudit(int $auditId): array
    {
        if (!self::photosTableReady()) {
            return [];
        }
        $rows = Database::fetchAll(
            'SELECT photo_id, cabinet_audit_id, cabinet_id, position_u, face, rel_path, caption, created_at
             FROM cabinet_audit_photos
             WHERE cabinet_audit_id = ?
             ORDER BY photo_id',
            [$auditId]
        );
        foreach ($rows as &$r) {
            $r['url'] = App::url('media.php?f=' . rawurlencode((string)$r['rel_path']));
        }
        unset($r);
        return $rows;
    }

    /**
     * @param array<string,mixed> $file $_FILES entry
     * @return array<string,mixed>
     */
    public static function savePhoto(int $auditId, int $cabinetId, array $file, array $meta = []): array
    {
        if (!self::photosTableReady()) {
            throw new RuntimeException('Photo table is not ready. Reload the page after schema ensure.');
        }
        $n = (int)Database::fetchValue(
            'SELECT COUNT(*) FROM cabinet_audit_photos WHERE cabinet_audit_id = ?',
            [$auditId]
        );
        if ($n >= self::MAX_PHOTOS) {
            throw new RuntimeException('Maximum ' . self::MAX_PHOTOS . ' photos per audit.');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Photo upload failed.');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid photo upload.');
        }
        $size = (int)($file['size'] ?? 0);
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('Photo is too large (max 12 MB).');
        }

        $dirRel = 'audits/' . $cabinetId . '/' . $auditId;
        $dirAbs = App::ROOT . '/storage/uploads/' . $dirRel;
        if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true)) {
            throw new RuntimeException('Cannot create audit photo folder.');
        }
        $name = 'p' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.jpg';
        $abs = $dirAbs . '/' . $name;
        self::writeJpeg($tmp, $abs);
        $rel = $dirRel . '/' . $name;

        $u = isset($meta['position_u']) && $meta['position_u'] !== '' && $meta['position_u'] !== null
            ? (int)$meta['position_u']
            : null;
        $face = strtolower(trim((string)($meta['face'] ?? '')));
        if (!in_array($face, ['front', 'rear', ''], true)) {
            $face = '';
        }
        $caption = trim((string)($meta['caption'] ?? ''));
        if (mb_strlen($caption) > 200) {
            $caption = mb_substr($caption, 0, 200);
        }

        $id = Database::insert('cabinet_audit_photos', [
            'cabinet_audit_id' => $auditId,
            'cabinet_id' => $cabinetId,
            'position_u' => $u,
            'face' => $face !== '' ? $face : null,
            'rel_path' => $rel,
            'caption' => $caption !== '' ? $caption : null,
        ]);

        return [
            'photo_id' => $id,
            'rel_path' => $rel,
            'url' => App::url('media.php?f=' . rawurlencode($rel)),
        ];
    }

    public static function photosTableReady(): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            Database::query('SELECT TOP 1 photo_id FROM cabinet_audit_photos');
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
        return $ok;
    }

    public static function snapshotColumnReady(): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            Database::query('SELECT TOP 1 snapshot_json FROM cabinet_audits');
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
        return $ok;
    }

    private static function writeJpeg(string $source, string $dest): void
    {
        if (!function_exists('imagecreatefromstring')) {
            if (!@copy($source, $dest)) {
                throw new RuntimeException('Could not store photo (GD missing).');
            }
            return;
        }
        $raw = @file_get_contents($source);
        if ($raw === false) {
            throw new RuntimeException('Could not read photo.');
        }
        $im = @imagecreatefromstring($raw);
        if ($im === false) {
            throw new RuntimeException('File is not a valid image.');
        }
        $w = imagesx($im);
        $h = imagesy($im);
        $max = self::JPEG_MAX_EDGE;
        if ($w > $max || $h > $max) {
            $scale = min($max / $w, $max / $h);
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($im);
            $im = $dst;
        }
        if (!@imagejpeg($im, $dest, self::JPEG_QUALITY)) {
            imagedestroy($im);
            throw new RuntimeException('Could not write JPEG.');
        }
        imagedestroy($im);
    }
}
