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
}
