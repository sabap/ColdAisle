<?php
/**
 * ColdAisle - Image upload + resize (GD), preserve aspect ratio (never stretch).
 *
 * Stores a full-resolution faceplate plus a small (.sm) JPEG for rack elevation,
 * row view, and 3D texturing so those pages do not pull multi‑hundred‑KB files.
 */
declare(strict_types=1);

class ImageUpload
{
    /** Max front/rear faceplate canvas (px). Scale down only. */
    public const MAX_WIDTH = 480;
    public const MAX_HEIGHT_PER_U = 48; // e.g. 2U => 96px min ceiling; still capped by max height
    public const MAX_HEIGHT = 1200;
    public const JPEG_QUALITY = 85;

    /**
     * Small variant for elevations / row / 3D.
     * ~96px wide is enough for a rack face strip and keeps files tiny.
     */
    public const SM_MAX_WIDTH = 96;
    public const SM_MAX_HEIGHT = 240;
    public const SM_JPEG_QUALITY = 72;
    public const VARIANT_SM = 'sm';

    /**
     * Process an uploaded image into $destPath (JPEG or PNG).
     * Also writes the .sm companion JPEG next to it.
     * @return array{path:string,width:int,height:int}
     */
    public static function processUpload(array $file, string $destPath, int $uHeight = 1): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Image upload failed (code ' . ($file['error'] ?? '?') . ').');
        }
        if (!is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new RuntimeException('Invalid upload.');
        }
        return self::processFromPath($file['tmp_name'], $destPath, $uHeight);
    }

    /** Relative path under storage/uploads for web serving via media.php */
    public static function publicRelPath(string $absolutePath): string
    {
        $root = realpath(App::ROOT . '/storage/uploads');
        $real = realpath($absolutePath);
        if (!$root || !$real || !str_starts_with(strtolower($real), strtolower($root))) {
            return '';
        }
        $rel = substr($real, strlen($root));
        return ltrim(str_replace('\\', '/', $rel), '/');
    }

    /**
     * Process a local image file (e.g. downloaded from openDCIM) into $destPath.
     * @return array{path:string,width:int,height:int}
     */
    public static function processLocalFile(string $sourcePath, string $destPath, int $uHeight = 1): array
    {
        return self::processFromPath($sourcePath, $destPath, $uHeight);
    }

    /**
     * Resize/copy image from an arbitrary filesystem path (imports, CLI).
     * Writes full image + small (.sm.jpg) companion.
     * @return array{path:string,width:int,height:int}
     */
    public static function processFromPath(string $sourcePath, string $destPath, int $uHeight = 1): array
    {
        if (!is_file($sourcePath)) {
            throw new RuntimeException('Image file not found: ' . $sourcePath);
        }
        $info = @getimagesize($sourcePath);
        if ($info === false) {
            throw new RuntimeException('File is not a valid image.');
        }
        $mime = $info['mime'] ?? '';
        $src = self::loadGd($sourcePath, $mime);
        if (!$src) {
            throw new RuntimeException('Unsupported image type for import.');
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        $maxH = min(self::MAX_HEIGHT, max(self::MAX_HEIGHT_PER_U, $uHeight * self::MAX_HEIGHT_PER_U));
        $maxW = self::MAX_WIDTH;
        $scale = min(1.0, $maxW / max(1, $sw), $maxH / max(1, $sh));
        $dw = max(1, (int)round($sw * $scale));
        $dh = max(1, (int)round($sh * $scale));

        $dst = self::resample($src, $dw, $dh);
        imagedestroy($src);
        if (!$dst) {
            throw new RuntimeException('Could not allocate image buffer.');
        }

        $dir = dirname($destPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            imagedestroy($dst);
            throw new RuntimeException('Could not create upload directory.');
        }

        $ext = strtolower(pathinfo($destPath, PATHINFO_EXTENSION));
        $ok = false;
        if ($ext === 'png') {
            $ok = imagepng($dst, $destPath, 6);
        } else {
            if ($ext !== 'jpg' && $ext !== 'jpeg') {
                $destPath = preg_replace('/\.[^.]+$/', '', $destPath) . '.jpg';
            }
            $ok = imagejpeg($dst, $destPath, self::JPEG_QUALITY);
        }
        if (!$ok) {
            imagedestroy($dst);
            throw new RuntimeException('Could not write resized image.');
        }

        // Build small companion from the already-resized full image (cheaper than reloading source)
        try {
            self::writeSmVariantFromGd($dst, $destPath);
        } catch (Throwable $e) {
            // Non-fatal — full image is enough; sm can be generated lazily later
        }
        imagedestroy($dst);

        return ['path' => $destPath, 'width' => $dw, 'height' => $dh];
    }

    /**
     * Map a stored relative path to a variant path.
     * templates/12/front.jpg + sm → templates/12/front.sm.jpg
     */
    public static function variantRelPath(string $rel, string $variant = self::VARIANT_SM): string
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($rel === '' || $variant === '' || $variant === 'full') {
            return $rel;
        }
        $variant = strtolower(preg_replace('/[^a-z0-9]/', '', $variant) ?? '');
        if ($variant === '') {
            return $rel;
        }
        $dir = str_replace('\\', '/', dirname($rel));
        $base = pathinfo($rel, PATHINFO_FILENAME);
        // already a variant?
        if (str_ends_with($base, '.' . $variant)) {
            return $rel;
        }
        // strip existing .sm etc.
        $base = preg_replace('/\.(sm|thumb|md)$/i', '', $base) ?? $base;
        $prefix = ($dir === '.' || $dir === '') ? '' : ($dir . '/');
        // Small variants are always JPEG for size
        return $prefix . $base . '.' . $variant . '.jpg';
    }

    /**
     * Absolute filesystem path under storage/uploads for a relative media path.
     */
    public static function absUploadPath(string $rel): string
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        return App::ROOT . '/storage/uploads/' . $rel;
    }

    /**
     * Ensure a small variant exists for $rel (DB full path). Returns variant relative path or null.
     * Generates on demand when missing (backfill for pre-variant uploads).
     */
    public static function ensureVariant(string $rel, string $variant = self::VARIANT_SM): ?string
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($rel === '') {
            return null;
        }
        if ($variant === '' || $variant === 'full') {
            return is_file(self::absUploadPath($rel)) ? $rel : null;
        }

        $varRel = self::variantRelPath($rel, $variant);
        $varAbs = self::absUploadPath($varRel);
        if (is_file($varAbs) && filesize($varAbs) > 32) {
            return $varRel;
        }

        $fullAbs = self::absUploadPath($rel);
        if (!is_file($fullAbs)) {
            // Maybe DB already points at something that is missing
            return null;
        }

        try {
            if ($variant === self::VARIANT_SM) {
                self::writeSmVariantFromFile($fullAbs, $varAbs);
                return is_file($varAbs) ? $varRel : null;
            }
        } catch (Throwable $e) {
            return null;
        }
        return null;
    }

    /**
     * Build a media.php URL. For variant=sm, points at the .sm companion path
     * (media.php generates it on first request if missing) — no GD during page render.
     * @param 'full'|'sm' $variant
     */
    public static function mediaUrl(?string $rel, string $variant = 'full'): string
    {
        if ($rel === null || trim($rel) === '') {
            return '';
        }
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($variant === self::VARIANT_SM) {
            $smRel = self::variantRelPath($rel, self::VARIANT_SM);
            // If full is gone but sm exists, still serve sm; otherwise prefer sm URL
            $smAbs = self::absUploadPath($smRel);
            $fullAbs = self::absUploadPath($rel);
            if (is_file($smAbs) || is_file($fullAbs)) {
                $rel = $smRel;
            }
            // else leave $rel as full path (may 404)
        }
        return App::url('media.php?f=' . rawurlencode($rel));
    }

    /**
     * Delete full image + known companions for a relative path.
     */
    public static function deleteWithVariants(?string $rel): void
    {
        if ($rel === null || trim($rel) === '') {
            return;
        }
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        $paths = [
            self::absUploadPath($rel),
            self::absUploadPath(self::variantRelPath($rel, self::VARIANT_SM)),
        ];
        foreach ($paths as $p) {
            if (is_file($p)) {
                @unlink($p);
            }
        }
    }

    /**
     * Batch-generate missing .sm variants under storage/uploads/templates.
     * @return array{scanned:int,created:int,skipped:int,failed:int}
     */
    public static function backfillSmallVariants(?callable $log = null): array
    {
        $root = App::ROOT . '/storage/uploads/templates';
        $stats = ['scanned' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0];
        if (!is_dir($root)) {
            return $stats;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            // Only full faceplate files (front.jpg / rear.png) — skip existing .sm
            if (preg_match('/\.(sm|thumb|md)\./i', $name)) {
                continue;
            }
            if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $name)) {
                continue;
            }
            $stats['scanned']++;
            $abs = $file->getPathname();
            $varAbs = preg_replace('/\.[^.]+$/', '', $abs) . '.sm.jpg';
            if (is_file($varAbs) && filesize($varAbs) > 32) {
                $stats['skipped']++;
                continue;
            }
            try {
                self::writeSmVariantFromFile($abs, $varAbs);
                $stats['created']++;
                if ($log) {
                    $log('created ' . $varAbs);
                }
            } catch (Throwable $e) {
                $stats['failed']++;
                if ($log) {
                    $log('fail ' . $abs . ': ' . $e->getMessage());
                }
            }
        }
        return $stats;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** @return \GdImage|resource|false */
    private static function loadGd(string $path, string $mime)
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }

    /**
     * @param \GdImage|resource $src
     * @return \GdImage|resource|false
     */
    private static function resample($src, int $dw, int $dh)
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $dst = imagecreatetruecolor($dw, $dh);
        if (!$dst) {
            return false;
        }
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $dw, $dh, $transparent);
        imagealphablending($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
        return $dst;
    }

    /**
     * @param \GdImage|resource $src Already-decoded full (or original) image
     */
    private static function writeSmVariantFromGd($src, string $fullDestPath): void
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = min(1.0, self::SM_MAX_WIDTH / max(1, $sw), self::SM_MAX_HEIGHT / max(1, $sh));
        $dw = max(1, (int)round($sw * $scale));
        $dh = max(1, (int)round($sh * $scale));
        $sm = self::resample($src, $dw, $dh);
        if (!$sm) {
            throw new RuntimeException('Could not allocate small image buffer.');
        }
        $smPath = preg_replace('/\.[^.]+$/', '', $fullDestPath) . '.sm.jpg';
        $dir = dirname($smPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            imagedestroy($sm);
            throw new RuntimeException('Could not create upload directory for small variant.');
        }
        // Flatten onto dark slate so JPEG has no ugly black/white matte for rack faces
        $flat = imagecreatetruecolor($dw, $dh);
        if ($flat) {
            $bg = imagecolorallocate($flat, 15, 23, 42); // slate-900
            imagefilledrectangle($flat, 0, 0, $dw, $dh, $bg);
            imagecopy($flat, $sm, 0, 0, 0, 0, $dw, $dh);
            imagedestroy($sm);
            $ok = imagejpeg($flat, $smPath, self::SM_JPEG_QUALITY);
            imagedestroy($flat);
        } else {
            $ok = imagejpeg($sm, $smPath, self::SM_JPEG_QUALITY);
            imagedestroy($sm);
        }
        if (!$ok) {
            throw new RuntimeException('Could not write small image variant.');
        }
    }

    private static function writeSmVariantFromFile(string $sourceAbs, string $destAbs): void
    {
        $info = @getimagesize($sourceAbs);
        if ($info === false) {
            throw new RuntimeException('Not an image: ' . $sourceAbs);
        }
        $src = self::loadGd($sourceAbs, $info['mime'] ?? '');
        if (!$src) {
            throw new RuntimeException('Unsupported image: ' . $sourceAbs);
        }
        try {
            // writeSmVariantFromGd derives path from full dest — pass dest as if it were the full file
            // so we need the stem of destAbs without .sm
            $asFull = preg_replace('/\.sm\.jpg$/i', '.jpg', $destAbs) ?? $destAbs;
            self::writeSmVariantFromGd($src, $asFull);
        } finally {
            imagedestroy($src);
        }
    }
}
