<?php
/**
 * ColdAisle - Image upload + resize (GD), preserve aspect ratio (never stretch).
 */
declare(strict_types=1);

class ImageUpload
{
    /** Max front/rear faceplate canvas (px). Scale down only; pad transparent if needed later. */
    public const MAX_WIDTH = 480;
    public const MAX_HEIGHT_PER_U = 48; // e.g. 2U => 96px min ceiling; still capped by max height
    public const MAX_HEIGHT = 1200;

    /**
     * Process an uploaded image into $destPath (JPEG or PNG).
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

        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            throw new RuntimeException('File is not a valid image.');
        }
        $mime = $info['mime'] ?? '';
        $src = match ($mime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($file['tmp_name']),
            'image/png' => @imagecreatefrompng($file['tmp_name']),
            'image/gif' => @imagecreatefromgif($file['tmp_name']),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false,
            default => false,
        };
        if (!$src) {
            throw new RuntimeException('Unsupported image type. Use JPEG, PNG, GIF, or WebP.');
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        $maxH = min(self::MAX_HEIGHT, max(self::MAX_HEIGHT_PER_U, $uHeight * self::MAX_HEIGHT_PER_U));
        $maxW = self::MAX_WIDTH;

        // Scale down only — never upscale (avoids blur/distortion)
        $scale = min(1.0, $maxW / max(1, $sw), $maxH / max(1, $sh));
        $dw = max(1, (int)round($sw * $scale));
        $dh = max(1, (int)round($sh * $scale));

        $dst = imagecreatetruecolor($dw, $dh);
        if (!$dst) {
            imagedestroy($src);
            throw new RuntimeException('Could not allocate image buffer.');
        }
        // Preserve transparency for PNG
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $dw, $dh, $transparent);
        imagealphablending($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
        imagedestroy($src);

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
            // default JPEG
            if ($ext !== 'jpg' && $ext !== 'jpeg') {
                $destPath = preg_replace('/\.[^.]+$/', '', $destPath) . '.jpg';
            }
            $ok = imagejpeg($dst, $destPath, 85);
        }
        imagedestroy($dst);
        if (!$ok) {
            throw new RuntimeException('Could not write resized image.');
        }

        return ['path' => $destPath, 'width' => $dw, 'height' => $dh];
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
}
