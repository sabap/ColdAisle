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
     * Estimate rendered text width (thousandths of inch).
     * Thermal transfer + bold white-on-blue prints wider than screen Arial metrics.
     * Bias high so we scale down early rather than clip the last glyph.
     */
    private static function estimateTextWidth(string $text, int $fontSize, bool $primary): float
    {
        // ~0.78–0.85 em per char — deliberately high for printer hard-stop margin
        $factor = $primary ? 0.82 : 0.78;
        $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if (preg_match('/[0-9:.\-]/', $text)) {
            $factor += 0.04; // digits/colons run wide on thermal
        }
        return $len * $fontSize * $factor;
    }

    /**
     * Largest font size that fits the full string (no truncation).
     */
    private static function fontSizeToFit(string $text, int $maxW, int $maxFs, int $minFs, bool $primary): int
    {
        if ($text === '' || $maxW < 1) {
            return $minFs;
        }
        $fs = max($minFs, min($maxFs, $maxFs));
        while ($fs > $minFs && self::estimateTextWidth($text, $fs, $primary) > $maxW) {
            $fs--;
        }
        // If still too wide at minFs, keep minFs — SVG textLength will compress slightly
        return $fs;
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

        // --- BMP51 / thermal hard stops (measured ~3/8″ right dead zone) ---
        // Units = thousandths of an inch. Border stroke is centered on the path, so
        // the outer edge of ink is path ± stroke/2; keep that inside the printable area.
        $hardRight = 375; // 3/8″ unprintable / cut zone on the right
        $hardLeft = 70;   // modest left (sprocket side still needs a little air)
        $hardTB = 50;     // top/bottom

        $stroke = max(10, min(18, (int)round(min($W, $H) * 0.008))); // thin frame
        // Inset so entire stroke (outer half) clears the hard stops
        $outerL = $hardLeft + (int)ceil($stroke / 2);
        $outerR = $hardRight + (int)ceil($stroke / 2);
        $outerT = $hardTB + (int)ceil($stroke / 2);
        $outerB = $hardTB + (int)ceil($stroke / 2);

        // On very small media, hard-right 3/8″ would leave almost no room — scale proportionally
        $minContentW = 400; // ≥0.40″ for text+QR
        if ($W - $outerL - $outerR < $minContentW) {
            $budget = max($minContentW, (int)round($W * 0.55));
            $side = max(40, (int)floor(($W - $budget) / 2));
            $outerL = max($outerL, $side);
            $outerR = max((int)ceil($stroke / 2) + 40, $W - $outerL - $budget);
        }

        $bx = $outerL;
        $by = $outerT;
        $bw = max(200, $W - $outerL - $outerR);
        $bh = max(200, $H - $outerT - $outerB);
        $rx = max(28, min(70, (int)round(min($bw, $bh) * 0.06)));

        // Cell padding inside the border (~1–2 print px at 300 dpi ≈ 3–7 units; use ~12–16
        // so registration error does not kiss the stroke)
        $pad = max(12, (int)ceil($stroke / 2) + 10);
        $marginL = $bx + $pad;
        $marginY = $by + $pad;
        $contentW = max(100, $bw - 2 * $pad);
        $contentH = max(80, $bh - 2 * $pad);
        $gap = max(18, (int)round(min($W, $H) * 0.028));
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
        // Rounded border — fully inside the 3/8″ right hard-stop
        $parts[] = sprintf(
            '<rect x="%d" y="%d" width="%d" height="%d" rx="%d" ry="%d" fill="none" stroke="#000000" stroke-width="%d" stroke-linejoin="round"/>',
            $bx,
            $by,
            $bw,
            $bh,
            $rx,
            $rx,
            $stroke
        );

        $qrSize = 0;
        $qrX = $marginL;
        $qrY = $marginY;
        $textX = $marginL;
        $textBoxW = max(100, $contentW);
        $textBoxH = max(70, $contentH);

        if ($showQr) {
            if ($mode === 'side') {
                $qrSize = (int)min($contentH, (int)round($contentW * 0.36));
                $qrSize = max(200, min($qrSize, $contentH));
                $qrX = $bx + $bw - $pad - $qrSize;
                $qrY = $marginY;
                $textBoxW = max(100, $qrX - $marginL - $gap);
                $textBoxH = $contentH;
            } elseif ($mode === 'stack_qr') {
                $nameBudget = (int)round($contentH * 0.20);
                $qrSize = (int)min($contentW, $contentH - $nameBudget - $gap);
                $qrSize = max(160, $qrSize);
                $qrX = $marginL + (int)max(0, ($contentW - $qrSize) / 2);
                $qrY = $by + $bh - $pad - $qrSize;
                $textBoxW = $contentW;
                $textBoxH = max(50, $qrY - $marginY - $gap);
            } else {
                // stack: text top, QR below — both inside frame padding
                $qrSize = (int)min($contentW * 0.90, $contentH * 0.44);
                $qrSize = max(280, min($qrSize, $contentW));
                $qrX = $marginL;
                $qrY = $by + $bh - $pad - $qrSize;
                $textBoxW = $contentW;
                $textBoxH = max(100, $qrY - $marginY - $gap);
            }
        }

        // Build full line strings first (never split mid-value)
        $prepared = [];
        foreach ($lines as $line) {
            $isPrimary = !empty($line['primary']);
            $label = (string)($line['label'] ?? '');
            $value = (string)($line['value'] ?? '');
            $text = $label !== '' ? ($label . ' ' . $value) : $value;
            $prepared[] = ['text' => $text, 'primary' => $isPrimary];
        }

        $lineGapFactor = 1.14;
        $nPrimary = 0;
        $nDetail = 0;
        foreach ($prepared as $p) {
            if ($p['primary']) {
                $nPrimary++;
            } else {
                $nDetail++;
            }
        }

        // Height-based starting sizes
        $weightUnits = $nPrimary * 1.28 + max(0, $nDetail) * 1.0;
        if ($weightUnits < 1) {
            $weightUnits = 1.0;
        }
        $bodyFs = (int)floor($textBoxH / max(1.0, $weightUnits * $lineGapFactor));
        $maxBody = (int)round(min($textBoxH * 0.26, 120));
        $minBody = 48; // allow small type so long serial/MAC still fully prints
        $maxName = (int)round(min($textBoxH * 0.34, 150));
        $minName = 56;

        if ($mode === 'stack_qr') {
            $maxName = (int)round(min($textBoxH * 0.85, $textBoxW * 0.10, 100));
            $minName = 48;
            $maxBody = $maxName;
            $minBody = $minName;
        }

        $bodyFs = max($minBody, min($maxBody, $bodyFs));
        $nameFs = (int)round($bodyFs * 1.18);
        $nameFs = max($minName, min($maxName, $nameFs));

        // WIDTH: scale name and ALL detail lines to fit the longest field fully
        foreach ($prepared as $p) {
            if ($p['primary']) {
                $nameFs = self::fontSizeToFit($p['text'], $textBoxW, $nameFs, $minName, true);
            }
        }
        $longestDetail = '';
        foreach ($prepared as $p) {
            if (!$p['primary']) {
                $len = function_exists('mb_strlen') ? mb_strlen($p['text']) : strlen($p['text']);
                $best = function_exists('mb_strlen') ? mb_strlen($longestDetail) : strlen($longestDetail);
                if ($len > $best) {
                    $longestDetail = $p['text'];
                }
            }
        }
        if ($longestDetail !== '') {
            $bodyFs = self::fontSizeToFit($longestDetail, $textBoxW, $bodyFs, $minBody, false);
            // Also ensure every detail line fits at that size (paranoia)
            foreach ($prepared as $p) {
                if (!$p['primary']) {
                    $bodyFs = self::fontSizeToFit($p['text'], $textBoxW, $bodyFs, $minBody, false);
                }
            }
        }

        // Vertical: if stack overflows, scale both down together (keep ratio)
        $stackH = 0;
        foreach ($prepared as $p) {
            $fs = $p['primary'] ? $nameFs : $bodyFs;
            $stackH += (int)round($fs * $lineGapFactor);
        }
        if ($stackH > $textBoxH && $stackH > 0) {
            $scale = $textBoxH / $stackH;
            $nameFs = max($minName, (int)floor($nameFs * $scale));
            $bodyFs = max($minBody, (int)floor($bodyFs * $scale));
            // Re-check width after vertical shrink (usually ok); re-fit if needed
            foreach ($prepared as $p) {
                if ($p['primary']) {
                    $nameFs = self::fontSizeToFit($p['text'], $textBoxW, $nameFs, $minName, true);
                } else {
                    $bodyFs = self::fontSizeToFit($p['text'], $textBoxW, $bodyFs, $minBody, false);
                }
            }
        }

        // Top-left pack
        $y = $marginY + $nameFs;
        $fontFamily = 'Arial, Helvetica, sans-serif';

        foreach ($prepared as $p) {
            $isPrimary = $p['primary'];
            $text = $p['text'];
            $fontSize = $isPrimary ? $nameFs : $bodyFs;
            $weight = $isPrimary ? '700' : '600';
            $est = self::estimateTextWidth($text, $fontSize, $isPrimary);
            // Hard SVG cap only when still over after scaling (min font + long string)
            $useLen = $est > $textBoxW;
            if ($useLen) {
                $parts[] = sprintf(
                    '<text x="%d" y="%d" text-anchor="start" font-family="%s" font-size="%d" font-weight="%s" fill="#000000" textLength="%d" lengthAdjust="spacingAndGlyphs">%s</text>',
                    $textX,
                    $y,
                    $fontFamily,
                    $fontSize,
                    $weight,
                    $textBoxW,
                    self::xmlEsc($text)
                );
            } else {
                $parts[] = sprintf(
                    '<text x="%d" y="%d" text-anchor="start" font-family="%s" font-size="%d" font-weight="%s" fill="#000000">%s</text>',
                    $textX,
                    $y,
                    $fontFamily,
                    $fontSize,
                    $weight,
                    self::xmlEsc($text)
                );
            }
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
