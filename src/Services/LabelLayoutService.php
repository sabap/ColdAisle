<?php
/**
 * Physical label layouts for popular label printers (Brady BMP51, Zebra, Avery, generic).
 *
 * Each printer defines media presets (width × height inches). Layout packs content
 * top-left and fits text to the printable width so thermal transfer does not clip.
 */
declare(strict_types=1);

class LabelLayoutService
{
    /**
     * Printer families → presets.
     *
     * @var array<string,array{
     *   label:string,
     *   notes:string,
     *   presets:array<string,array{w:float,h:float,label:string,layout?:string}>
     * }>
     */
    public const PRINTERS = [
        'brady_bmp51' => [
            'label' => 'Brady BMP51',
            'notes' => 'Windows dialog usually offers 1×1, 1.5×1, and 2×2. Prefer those for Print…; use SVG for continuous 1.50″ vinyl lengths.',
            'presets' => [
                'bmp51_2x2' => [
                    'w' => 2.0,
                    'h' => 2.0,
                    'label' => '2″ × 2″ (dialog — recommended)',
                    'layout' => 'stack',
                ],
                'bmp51_1_5x1' => [
                    'w' => 1.5,
                    'h' => 1.0,
                    'label' => '1.5″ × 1″ (dialog)',
                    'layout' => 'side',
                ],
                'bmp51_1x1' => [
                    'w' => 1.0,
                    'h' => 1.0,
                    'label' => '1″ × 1″ (dialog — QR + name)',
                    'layout' => 'stack_qr',
                ],
                'bmp51_cont_h2' => [
                    'w' => 2.0,
                    'h' => 1.5,
                    'label' => 'Continuous 2″ × 1.50″ (horizontal)',
                    'layout' => 'side',
                ],
                'bmp51_cont_h3' => [
                    'w' => 3.0,
                    'h' => 1.5,
                    'label' => 'Continuous 3″ × 1.50″ (horizontal)',
                    'layout' => 'side',
                ],
                'bmp51_cont_v2' => [
                    'w' => 1.5,
                    'h' => 2.0,
                    'label' => 'Continuous 1.50″ × 2″ (vertical)',
                    'layout' => 'stack',
                ],
                'bmp51_cont_v3' => [
                    'w' => 1.5,
                    'h' => 3.0,
                    'label' => 'Continuous 1.50″ × 3″ (vertical)',
                    'layout' => 'stack',
                ],
            ],
        ],
        'zebra' => [
            'label' => 'Zebra desktop (GK/ZD class)',
            'notes' => 'Common thermal sizes. Select matching stock in the Zebra driver or use SVG → ZebraDesigner.',
            'presets' => [
                'zebra_2x1' => [
                    'w' => 2.0,
                    'h' => 1.0,
                    'label' => '2″ × 1″',
                    'layout' => 'side',
                ],
                'zebra_2x2' => [
                    'w' => 2.0,
                    'h' => 2.0,
                    'label' => '2″ × 2″',
                    'layout' => 'stack',
                ],
                'zebra_3x2' => [
                    'w' => 3.0,
                    'h' => 2.0,
                    'label' => '3″ × 2″',
                    'layout' => 'side',
                ],
                'zebra_4x1' => [
                    'w' => 4.0,
                    'h' => 1.0,
                    'label' => '4″ × 1″',
                    'layout' => 'side',
                ],
                'zebra_4x2' => [
                    'w' => 4.0,
                    'h' => 2.0,
                    'label' => '4″ × 2″',
                    'layout' => 'side',
                ],
            ],
        ],
        'avery' => [
            'label' => 'Avery (single-label sheet sizes)',
            'notes' => 'Prints one label at the Avery face size. For multi-up sheets, download SVG and place in Avery Design & Print.',
            'presets' => [
                'avery_5160' => [
                    'w' => 2.625,
                    'h' => 1.0,
                    'label' => '5160 — 1″ × 2-5/8″',
                    'layout' => 'side',
                ],
                'avery_5163' => [
                    'w' => 4.0,
                    'h' => 2.0,
                    'label' => '5163 — 2″ × 4″',
                    'layout' => 'side',
                ],
                'avery_94207' => [
                    'w' => 2.0,
                    'h' => 2.0,
                    'label' => '94207 — 2″ × 2″ square',
                    'layout' => 'stack',
                ],
                'avery_5293' => [
                    'w' => 1.5,
                    'h' => 1.5,
                    'label' => '5293 — 1.5″ × 1.5″',
                    'layout' => 'stack',
                ],
            ],
        ],
        'generic' => [
            'label' => 'Generic / other',
            'notes' => 'Common sizes for laser/inkjet or unknown thermal printers.',
            'presets' => [
                'gen_2x1' => [
                    'w' => 2.0,
                    'h' => 1.0,
                    'label' => '2″ × 1″',
                    'layout' => 'side',
                ],
                'gen_2x2' => [
                    'w' => 2.0,
                    'h' => 2.0,
                    'label' => '2″ × 2″',
                    'layout' => 'stack',
                ],
                'gen_3x2' => [
                    'w' => 3.0,
                    'h' => 2.0,
                    'label' => '3″ × 2″',
                    'layout' => 'side',
                ],
                'gen_4x2' => [
                    'w' => 4.0,
                    'h' => 2.0,
                    'label' => '4″ × 2″',
                    'layout' => 'side',
                ],
            ],
        ],
    ];

    public const DEFAULT_PRINTER = 'brady_bmp51';
    public const DEFAULT_MEDIA = 'bmp51_2x2';

    /** Legacy media key aliases → current keys */
    private const MEDIA_ALIASES = [
        'brady_2x2' => 'bmp51_2x2',
        'brady_1_5x1' => 'bmp51_1_5x1',
        'brady_1x1' => 'bmp51_1x1',
        'cont_h2' => 'bmp51_cont_h2',
        'cont_h3' => 'bmp51_cont_h3',
        'cont_v2' => 'bmp51_cont_v2',
        'cont_v3' => 'bmp51_cont_v3',
    ];

    /**
     * @return array{printer:string,media:string,w:float,h:float,label:string,layout:string,notes:string}|null
     */
    public static function resolvePreset(?string $printer, ?string $media): ?array
    {
        $media = trim((string)$media);
        if (isset(self::MEDIA_ALIASES[$media])) {
            $media = self::MEDIA_ALIASES[$media];
        }
        $printer = trim((string)$printer);

        // Find media in any printer if printer omitted / wrong
        foreach (self::PRINTERS as $pKey => $pDef) {
            if ($media !== '' && isset($pDef['presets'][$media])) {
                if ($printer === '' || $printer === $pKey) {
                    $pr = $pDef['presets'][$media];
                    return [
                        'printer' => $pKey,
                        'media' => $media,
                        'w' => (float)$pr['w'],
                        'h' => (float)$pr['h'],
                        'label' => (string)$pr['label'],
                        'layout' => (string)($pr['layout'] ?? 'stack'),
                        'notes' => (string)($pDef['notes'] ?? ''),
                    ];
                }
            }
        }

        if ($printer === '' || !isset(self::PRINTERS[$printer])) {
            $printer = self::DEFAULT_PRINTER;
        }
        $pDef = self::PRINTERS[$printer];
        $media = self::DEFAULT_MEDIA;
        if ($printer !== self::DEFAULT_PRINTER) {
            $media = array_key_first($pDef['presets']) ?: self::DEFAULT_MEDIA;
        }
        if (!isset($pDef['presets'][$media])) {
            $media = (string)array_key_first($pDef['presets']);
        }
        $pr = $pDef['presets'][$media];
        return [
            'printer' => $printer,
            'media' => $media,
            'w' => (float)$pr['w'],
            'h' => (float)$pr['h'],
            'label' => (string)$pr['label'],
            'layout' => (string)($pr['layout'] ?? 'stack'),
            'notes' => (string)($pDef['notes'] ?? ''),
        ];
    }

    /**
     * Flat list of all media keys (compat).
     * @return array<string,array{w:float,h:float,label:string,group:string}>
     */
    public static function allMediaPresets(): array
    {
        $out = [];
        foreach (self::PRINTERS as $pKey => $pDef) {
            foreach ($pDef['presets'] as $mKey => $pr) {
                $out[$mKey] = [
                    'w' => (float)$pr['w'],
                    'h' => (float)$pr['h'],
                    'label' => (string)$pr['label'],
                    'group' => $pKey,
                ];
            }
        }
        return $out;
    }

    /** @deprecated */
    public const MEDIA_PRESETS = []; // populated at runtime via allMediaPresets if needed

    /** @deprecated */
    public const LENGTH_PRESETS_IN = [2.0, 2.5, 3.0, 4.0];

    /**
     * @param array{
     *   name?:string,ip?:?string,serial?:?string,mac?:?string,
     *   url?:string,show_ip?:bool,show_serial?:bool,show_mac?:bool,show_qr?:bool,
     *   printer?:string,media?:string,orient?:string,length_in?:float
     * } $opts
     * @return array<string,mixed>
     */
    public static function pduLabel(array $opts): array
    {
        $resolved = self::resolvePreset(
            isset($opts['printer']) ? (string)$opts['printer'] : null,
            isset($opts['media']) ? (string)$opts['media'] : null
        );

        // Legacy orient + length only if no media
        if (
            $resolved
            && empty($opts['media'])
            && empty($opts['printer'])
            && (isset($opts['orient']) || isset($opts['length_in']))
        ) {
            $orient = strtolower(trim((string)($opts['orient'] ?? 'landscape')));
            $length = (float)($opts['length_in'] ?? 2.0);
            if ($length < 1.0) {
                $length = 1.0;
            }
            if ($orient === 'portrait') {
                $widthIn = 1.5;
                $heightIn = $length;
                $layoutMode = 'stack';
            } else {
                $widthIn = $length;
                $heightIn = 1.5;
                $layoutMode = 'side';
            }
            $printer = 'brady_bmp51';
            $media = 'custom';
            $notes = '';
        } else {
            if (!$resolved) {
                $resolved = self::resolvePreset(self::DEFAULT_PRINTER, self::DEFAULT_MEDIA);
            }
            $widthIn = $resolved['w'];
            $heightIn = $resolved['h'];
            $layoutMode = $resolved['layout'];
            $printer = $resolved['printer'];
            $media = $resolved['media'];
            $notes = $resolved['notes'];
            $length = max($widthIn, $heightIn);
            $orient = $widthIn >= $heightIn ? 'landscape' : 'portrait';
        }

        $name = trim((string)($opts['name'] ?? 'PDU'));
        if ($name === '') {
            $name = 'PDU';
        }
        $showIp = ($opts['show_ip'] ?? true) !== false;
        $showSerial = ($opts['show_serial'] ?? true) !== false;
        $showMac = ($opts['show_mac'] ?? true) !== false;
        $showQr = ($opts['show_qr'] ?? true) !== false;

        $tiny = ($widthIn <= 1.05 && $heightIn <= 1.05) || $layoutMode === 'stack_qr';

        $lines = [];
        $lines[] = ['label' => '', 'value' => $name, 'primary' => true];
        if (!$tiny) {
            $ip = trim((string)($opts['ip'] ?? ''));
            if ($showIp && $ip !== '') {
                $lines[] = ['label' => 'IP', 'value' => $ip];
            }
            $serial = trim((string)($opts['serial'] ?? ''));
            if ($showSerial && $serial !== '') {
                $lines[] = ['label' => 'SN', 'value' => $serial];
            }
            $mac = trim((string)($opts['mac'] ?? ''));
            if ($showMac && $mac !== '') {
                $lines[] = ['label' => 'MAC', 'value' => self::formatMac($mac)];
            }
        }

        $url = trim((string)($opts['url'] ?? ''));

        return [
            'width_in' => round($widthIn, 3),
            'height_in' => round($heightIn, 3),
            'printer' => $printer,
            'media' => $media,
            'orient' => $orient ?? 'landscape',
            'length_in' => round($length ?? max($widthIn, $heightIn), 3),
            'tape_width_in' => 1.50,
            'lines' => $lines,
            'qr_url' => $url,
            'show_qr' => $showQr && $url !== '',
            'title' => $name,
            'layout_mode' => $layoutMode,
            'notes' => $notes ?? '',
            'preset_label' => $resolved['label'] ?? '',
        ];
    }

    public static function formatMac(string $mac): string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac) ?? '');
        if (strlen($hex) === 12) {
            return implode(':', str_split($hex, 2));
        }
        return trim($mac);
    }

    /**
     * Estimate rendered text width (thousandths of inch) for Arial bold-ish.
     * Thermal + white-on-dark vinyl tends to look wider than screen metrics.
     */
    private static function estimateTextWidth(string $text, int $fontSize, bool $primary): float
    {
        // Conservative factors so we never clip (was ~0.58–0.62 → overflow on print)
        $factor = $primary ? 0.72 : 0.68;
        $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        // Slightly wider for digits/colons in MAC/IP
        if (preg_match('/[0-9:]/', $text)) {
            $factor += 0.03;
        }
        return $len * $fontSize * $factor;
    }

    /**
     * Fit one line into max width: shrink font first, then truncate.
     * @return array{0:string,1:int} text, fontSize
     */
    private static function fitLine(string $text, int $fontSize, int $minFs, int $maxW, bool $primary): array
    {
        $fs = $fontSize;
        while ($fs > $minFs && self::estimateTextWidth($text, $fs, $primary) > $maxW) {
            $fs--;
        }
        if (self::estimateTextWidth($text, $fs, $primary) <= $maxW) {
            return [$text, $fs];
        }
        // Truncate until it fits
        $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        while ($len > 4) {
            $len--;
            $cut = function_exists('mb_substr') ? mb_substr($text, 0, $len) : substr($text, 0, $len);
            $try = $cut . '..';
            if (self::estimateTextWidth($try, $fs, $primary) <= $maxW) {
                return [$try, $fs];
            }
        }
        return [function_exists('mb_substr') ? mb_substr($text, 0, 3) . '..' : substr($text, 0, 3) . '..', $fs];
    }

    /**
     * @param array<string,mixed> $layout
     */
    public static function toSvg(array $layout, string $qrSvgInner = ''): string
    {
        $wIn = (float)$layout['width_in'];
        $hIn = (float)$layout['height_in'];
        $W = (int)round($wIn * 1000);
        $H = (int)round($hIn * 1000);
        // Extra side margin — physical printable area is often inside die-cut edge
        $marginX = max(50, (int)round($W * 0.06)); // ≥0.05″, ~6%
        $marginY = max(40, (int)round($H * 0.05));
        $gap = max(24, (int)round(min($W, $H) * 0.035));
        $innerW = $W - 2 * $marginX;
        $innerH = $H - 2 * $marginY;
        $mode = (string)($layout['layout_mode'] ?? 'stack');
        $showQr = !empty($layout['show_qr']) && $qrSvgInner !== '';
        $lines = is_array($layout['lines'] ?? null) ? $layout['lines'] : [];

        $parts = [];
        $parts[] = sprintf(
            '<svg class="label-svg" xmlns="http://www.w3.org/2000/svg" width="%sin" height="%sin" viewBox="0 0 %d %d">',
            rtrim(rtrim(number_format($wIn, 3, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format($hIn, 3, '.', ''), '0'), '.'),
            $W,
            $H
        );
        $parts[] = sprintf('<rect x="0" y="0" width="%d" height="%d" fill="#ffffff"/>', $W, $H);

        $qrSize = 0;
        $qrX = $marginX;
        $qrY = $marginY;
        $textX = $marginX;
        $textBoxW = $innerW;
        $textBoxH = $innerH;

        if ($showQr) {
            if ($mode === 'side') {
                $qrSize = (int)min($innerH, (int)round($innerW * 0.38));
                $qrSize = max(260, min($qrSize, $innerH));
                $qrX = $W - $marginX - $qrSize;
                $qrY = $marginY;
                $textBoxW = max(160, $qrX - $marginX - $gap);
                $textBoxH = $innerH;
            } elseif ($mode === 'stack_qr') {
                $nameBudget = (int)round($innerH * 0.20);
                $qrSize = (int)min($innerW, $innerH - $nameBudget - $gap);
                $qrSize = max(200, $qrSize);
                $qrX = $marginX + (int)max(0, ($innerW - $qrSize) / 2);
                $qrY = $H - $marginY - $qrSize;
                $textBoxW = $innerW;
                $textBoxH = max(70, $qrY - $marginY - $gap);
            } else {
                // stack: text top-left, QR bottom-left (leave side gutter so QR doesn't force text overflow)
                $qrSize = (int)min($innerW * 0.88, $innerH * 0.46);
                $qrSize = max(340, min($qrSize, $innerW));
                $qrX = $marginX;
                $qrY = $H - $marginY - $qrSize;
                $textBoxW = $innerW;
                $textBoxH = max(140, $qrY - $marginY - $gap);
            }
        }

        $lineGapFactor = 1.14;
        $weightUnits = 0.0;
        foreach ($lines as $line) {
            $weightUnits += !empty($line['primary']) ? 1.28 : 1.0;
        }
        if ($weightUnits < 1) {
            $weightUnits = 1.0;
        }

        $bodyFs = (int)floor($textBoxH / max(1.0, $weightUnits * $lineGapFactor));
        // Slightly smaller caps so full MAC/name fit on ~1.4″ usable width
        $maxBody = (int)round(min($textBoxH * 0.26, $textBoxW * 0.085, 128));
        $minBody = 64;
        $maxName = (int)round(min($textBoxH * 0.34, $textBoxW * 0.12, 168));
        $minName = 80;

        if ($mode === 'stack_qr') {
            $maxName = (int)round(min($textBoxH * 0.85, $textBoxW * 0.11, 110));
            $minName = 64;
            $maxBody = $maxName;
            $minBody = $minName;
        }

        $bodyFs = max($minBody, min($maxBody, $bodyFs));
        $nameFs = (int)round($bodyFs * 1.22);
        $nameFs = max($minName, min($maxName, $nameFs));

        $stackH = 0;
        foreach ($lines as $line) {
            $fs = !empty($line['primary']) ? $nameFs : $bodyFs;
            $stackH += (int)round($fs * $lineGapFactor);
        }
        if ($stackH > $textBoxH && $stackH > 0) {
            $scale = $textBoxH / $stackH;
            $nameFs = max((int)($minName * 0.9), (int)floor($nameFs * $scale));
            $bodyFs = max((int)($minBody * 0.9), (int)floor($bodyFs * $scale));
        }

        // Top-left pack
        $y = $marginY + $nameFs;
        $fontFamily = 'Arial, Helvetica, sans-serif';

        foreach ($lines as $line) {
            $isPrimary = !empty($line['primary']);
            $label = (string)($line['label'] ?? '');
            $value = (string)($line['value'] ?? '');
            $fontSize = $isPrimary ? $nameFs : $bodyFs;
            $minFs = $isPrimary ? max(60, (int)($minName * 0.85)) : max(56, (int)($minBody * 0.85));
            if ($label !== '') {
                $text = $label . ' ' . $value;
            } else {
                $text = $value;
            }
            [$text, $fontSize] = self::fitLine($text, $fontSize, $minFs, $textBoxW, $isPrimary);
            $weight = $isPrimary ? '700' : '600';
            // Optional SVG textLength as safety net (never exceed box)
            $parts[] = sprintf(
                '<text x="%d" y="%d" text-anchor="start" font-family="%s" font-size="%d" font-weight="%s" fill="#000000">%s</text>',
                $textX,
                $y,
                $fontFamily,
                $fontSize,
                $weight,
                self::xmlEsc($text)
            );
            $y += (int)round($fontSize * $lineGapFactor);
        }

        if ($showQr && $qrSize > 0) {
            $inner = $qrSvgInner;
            if (preg_match('/viewBox="0 0 ([\d.]+) ([\d.]+)"/', $inner, $vm)) {
                $qn = (float)$vm[1];
            } else {
                $qn = 33.0;
            }
            $innerBody = preg_replace('/^[\s\S]*?<svg[^>]*>|<\/svg>\s*$/i', '', $inner) ?? '';
            $scale = $qrSize / max(1.0, $qn);
            $parts[] = sprintf(
                '<g transform="translate(%d,%d) scale(%.5f)">%s</g>',
                $qrX,
                $qrY,
                $scale,
                $innerBody
            );
        }

        $parts[] = '</svg>';
        return implode('', $parts);
    }

    private static function xmlEsc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
