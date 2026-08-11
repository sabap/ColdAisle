<?php
/**
 * QR code generation for printable labels and deep links.
 * Wraps vendored pure-PHP encoder (MIT) — no Composer required.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/Lib/splitbrain_QRCode.php';

class QrCodeService
{
    /**
     * SVG fragment/document for a URL or arbitrary payload.
     *
     * @param array{ecl?:string,fill?:string,bg?:string} $options
     *   ecl: L|M|Q|H (default M)
     */
    public static function svg(string $data, array $options = []): string
    {
        $data = trim($data);
        if ($data === '') {
            return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"></svg>';
        }
        $ecl = strtoupper((string)($options['ecl'] ?? 'M'));
        $s = match ($ecl) {
            'L' => 'qrl',
            'Q' => 'qrq',
            'H' => 'qrh',
            default => 'qrm',
        };
        $svg = \splitbrain\phpQRCode\QRCode::svg($data, ['s' => $s]);
        // Encoder draws black rects without fill attr — fine for mono print
        $fill = (string)($options['fill'] ?? '#000000');
        $bg = (string)($options['bg'] ?? '#ffffff');
        if ($fill !== '#000000' || $bg !== '#ffffff') {
            $svg = preg_replace(
                '/<svg([^>]*)>/',
                '<svg$1><rect width="100%" height="100%" fill="' . htmlspecialchars($bg, ENT_QUOTES) . '"/>',
                $svg,
                1
            ) ?? $svg;
            $svg = str_replace('<rect x=', '<rect fill="' . htmlspecialchars($fill, ENT_QUOTES) . '" x=', $svg);
        }
        return $svg;
    }

    /**
     * SVG with modules filled black and optional quiet zone (viewBox padding).
     */
    public static function svgLabel(string $data, float $sizeModulesPad = 1.0): string
    {
        $raw = self::svg($data, ['ecl' => 'M']);
        if (!preg_match('/viewBox="0 0 (\d+) (\d+)"/', $raw, $m)) {
            return $raw;
        }
        $n = (int)$m[1];
        $pad = max(0, (int)round($sizeModulesPad));
        $outer = $n + 2 * $pad;
        $inner = preg_replace('/^<svg[^>]*>|<\/svg>$/', '', $raw) ?? '';
        // Shift modules by pad; white background for vinyl contrast
        $body = '<rect x="0" y="0" width="' . $outer . '" height="' . $outer . '" fill="#ffffff"/>';
        $body .= '<g transform="translate(' . $pad . ',' . $pad . ')" fill="#000000">' . $inner . '</g>';
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $outer . ' ' . $outer . '" shape-rendering="crispEdges">'
            . $body . '</svg>';
    }

    /**
     * PNG binary for a QR (requires GD). Returns null if GD unavailable.
     */
    public static function png(string $data, int $scale = 8, int $margin = 2): ?string
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
            return null;
        }
        $data = trim($data);
        if ($data === '') {
            return null;
        }
        // Build from SVG-less matrix via temporary high-contrast SVG raster is hard —
        // draw using the encoder's raw module path: re-render SVG viewBox size.
        $svg = self::svgLabel($data, (float)$margin);
        if (!preg_match('/viewBox="0 0 (\d+) (\d+)"/', $svg, $m)) {
            return null;
        }
        $modules = (int)$m[1];
        $scale = max(2, min(20, $scale));
        $px = $modules * $scale;
        $im = imagecreatetruecolor($px, $px);
        if ($im === false) {
            return null;
        }
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 0, 0, 0);
        imagefilledrectangle($im, 0, 0, $px, $px, $white);
        // Parse module rects from SVG
        if (preg_match_all('/<rect[^>]+x="(\d+)"[^>]+y="(\d+)"[^>]+width="(\d+)"[^>]+height="(\d+)"/', $svg, $rects, PREG_SET_ORDER)) {
            foreach ($rects as $r) {
                $x = (int)$r[1];
                $y = (int)$r[2];
                $w = (int)$r[3];
                $h = (int)$r[4];
                // Skip full-background white rect
                if ($w >= $modules - 1 && $h >= $modules - 1 && $x === 0 && $y === 0) {
                    continue;
                }
                imagefilledrectangle(
                    $im,
                    $x * $scale,
                    $y * $scale,
                    ($x + $w) * $scale - 1,
                    ($y + $h) * $scale - 1,
                    $black
                );
            }
        }
        ob_start();
        imagepng($im);
        $bin = ob_get_clean();
        imagedestroy($im);
        return is_string($bin) && $bin !== '' ? $bin : null;
    }
}
