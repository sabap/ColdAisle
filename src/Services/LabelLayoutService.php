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
            // Continuous indoor vinyl is 1.50″ wide — page may be 2×2 for the Windows dialog,
            // but ink must stay within the left 1.50″ or the right border is cut off the tape.
            'notes' => '1.50″ continuous vinyl: artwork is capped at 1.50″ wide (even for 2×2 dialog). Match dialog size when printing; blank area on a 2″ page is intentional.',
            'presets' => [
                'bmp51_2x2' => [
                    'w' => 2.0,
                    'h' => 2.0,
                    'label' => '2″ × 2″ dialog (1.50″ tape-safe)',
                    'layout' => 'stack',
                    'tape_w' => 1.5, // physical continuous width
                ],
                'bmp51_1_5x1' => [
                    'w' => 1.5,
                    'h' => 1.0,
                    'label' => '1.5″ × 1″ (dialog / tape width)',
                    'layout' => 'side',
                    'tape_w' => 1.5,
                ],
                'bmp51_1x1' => [
                    'w' => 1.0,
                    'h' => 1.0,
                    'label' => '1″ × 1″ (dialog — QR + name)',
                    'layout' => 'stack_qr',
                    'tape_w' => 1.0,
                ],
                'bmp51_cont_h2' => [
                    'w' => 2.0,
                    'h' => 1.5,
                    'label' => 'Continuous 2″ long × 1.50″ (horizontal)',
                    'layout' => 'side',
                    'tape_w' => 1.5,
                ],
                'bmp51_cont_h3' => [
                    'w' => 3.0,
                    'h' => 1.5,
                    'label' => 'Continuous 3″ long × 1.50″ (horizontal)',
                    'layout' => 'side',
                    'tape_w' => 1.5,
                ],
                'bmp51_cont_v2' => [
                    'w' => 1.5,
                    'h' => 2.0,
                    'label' => 'Continuous 1.50″ × 2″ (vertical)',
                    'layout' => 'stack',
                    'tape_w' => 1.5,
                ],
                'bmp51_cont_v3' => [
                    'w' => 1.5,
                    'h' => 3.0,
                    'label' => 'Continuous 1.50″ × 3″ (vertical)',
                    'layout' => 'stack',
                    'tape_w' => 1.5,
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
                        'tape_w' => isset($pr['tape_w']) ? (float)$pr['tape_w'] : (float)$pr['w'],
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
            'tape_w' => isset($pr['tape_w']) ? (float)$pr['tape_w'] : (float)$pr['w'],
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
            $tapeW = min(1.5, $widthIn); // continuous cartridge width
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
            $tapeW = (float)($resolved['tape_w'] ?? $widthIn);
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
            // Physical cartridge / printable width (may be < page width, e.g. 1.50″ tape on 2×2 dialog)
            'tape_w' => round($tapeW ?? $widthIn, 3),
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
     * Cabinet plaque / ID label (reuses printer presets; location line instead of IP/SN).
     *
     * @param array{
     *   name?:string,location?:?string,u_height?:?int|string,
     *   url?:string,show_location?:bool,show_u?:bool,show_qr?:bool,
     *   printer?:string,media?:string
     * } $opts
     * @return array<string,mixed>
     */
    public static function cabinetLabel(array $opts): array
    {
        $name = trim((string)($opts['name'] ?? 'Cabinet'));
        if ($name === '') {
            $name = 'Cabinet';
        }
        $loc = trim((string)($opts['location'] ?? ''));
        $u = $opts['u_height'] ?? null;
        $uStr = ($u !== null && $u !== '' && (int)$u > 0) ? ((int)$u . 'U') : '';

        // Map to PDU label packer: location as “IP” slot text without IP: prefix when primary lines only
        $layout = self::pduLabel([
            'name' => $name,
            'ip' => ($opts['show_location'] ?? true) !== false ? $loc : '',
            'serial' => ($opts['show_u'] ?? true) !== false ? $uStr : '',
            'mac' => '',
            'url' => (string)($opts['url'] ?? ''),
            'show_ip' => ($opts['show_location'] ?? true) !== false && $loc !== '',
            'show_serial' => ($opts['show_u'] ?? true) !== false && $uStr !== '',
            'show_mac' => false,
            'show_qr' => ($opts['show_qr'] ?? true) !== false,
            'printer' => $opts['printer'] ?? null,
            'media' => $opts['media'] ?? null,
        ]);

        // Relabel secondary lines for cabinet semantics (LOC / U)
        $lines = [];
        foreach ($layout['lines'] as $ln) {
            if (!empty($ln['primary'])) {
                $lines[] = $ln;
                continue;
            }
            $lab = (string)($ln['label'] ?? '');
            if ($lab === 'IP') {
                $ln['label'] = 'LOC';
            } elseif ($lab === 'SN') {
                $ln['label'] = '';
            }
            $lines[] = $ln;
        }
        $layout['lines'] = $lines;
        $layout['title'] = $name;
        return $layout;
    }

    /**
     * Estimate rendered text width (thousandths of inch).
     * Slightly conservative for thermal bold; not so high that type stays tiny.
     */
    private static function estimateTextWidth(string $text, int $fontSize, bool $primary): float
    {
        $factor = $primary ? 0.70 : 0.66;
        $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if (preg_match('/[0-9:.\-]/', $text)) {
            $factor += 0.03;
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
        return $fs;
    }

    /**
     * Grow font until the string nearly fills targetW (then back off one step if over).
     * Used for the PDU name so it spans the frame with only a tiny side gap.
     */
    private static function fontSizeToFillWidth(string $text, int $targetW, int $maxFs, int $minFs, bool $primary): int
    {
        if ($text === '' || $targetW < 1) {
            return $minFs;
        }
        // Seed from inverse of width estimate, then climb while still under target
        $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        $len = max(1, $len);
        $seedFactor = $primary ? 0.56 : 0.54;
        $fs = (int)floor($targetW / ($len * $seedFactor));
        $fs = max($minFs, min($maxFs, $fs));
        while ($fs > $minFs && self::estimateTextWidth($text, $fs, $primary) > $targetW) {
            $fs--;
        }
        while ($fs < $maxFs && self::estimateTextWidth($text, $fs + 1, $primary) <= $targetW) {
            $fs++;
        }
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

        // Physical ink width (e.g. BMP51 continuous vinyl = 1.50″). Page may be wider
        // for the Windows 2×2 dialog — keep ALL ink in the left tape_w inches so the
        // right side of the border is never cut off the tape.
        $tapeW = (float)($layout['tape_w'] ?? $wIn);
        if ($tapeW < 0.5) {
            $tapeW = $wIn;
        }
        $artW = (int)round(min($W, $tapeW * 1000));
        $artX = 0; // left-align on page (continuous feed / sprocket side)
        $artH = $H;
        $artY = 0;

        $stroke = max(10, min(16, (int)round(min($artW, $artH) * 0.008)));
        // Asymmetric edge: a few extra units on the RIGHT so the frame clears the tape edge
        $edgeL = max(26, (int)round(min($artW, $artH) * 0.032)) + (int)ceil($stroke / 2);
        $edgeR = $edgeL + 28; // ~0.028″ shorter frame on the right (right border fully on tape)
        $edgeT = max(26, (int)round(min($artW, $artH) * 0.032)) + (int)ceil($stroke / 2);
        $edgeB = $edgeT;

        $bx = $artX + $edgeL;
        $by = $artY + $edgeT;
        $bw = max(200, $artW - $edgeL - $edgeR);
        $bh = max(200, $artH - $edgeT - $edgeB);
        $rx = max(24, min(60, (int)round(min($bw, $bh) * 0.055)));

        // Cell padding inside border (a few print px past the stroke)
        $pad = max(12, (int)ceil($stroke / 2) + 10);
        $marginL = $bx + $pad;
        $marginY = $by + $pad;
        $contentW = max(100, $bw - 2 * $pad);
        $contentH = max(80, $bh - 2 * $pad);
        $gap = max(16, (int)round(min($artW, $artH) * 0.025));
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
        // Rounded border fully within tape width (left artW of page)
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
                $nameBudget = (int)round($contentH * 0.22);
                $qrSize = (int)min($contentW, $contentH - $nameBudget - $gap);
                $qrSize = max(160, $qrSize);
                $qrX = $marginL + (int)max(0, ($contentW - $qrSize) / 2);
                $qrY = $by + $bh - $pad - $qrSize;
                $textBoxW = $contentW;
                $textBoxH = max(50, $qrY - $marginY - $gap);
            } else {
                // stack: text top, QR below
                $qrSize = (int)min($contentW * 0.92, $contentH * 0.48);
                $qrSize = max(300, min($qrSize, $contentW));
                $qrX = $marginL;
                $qrY = $by + $bh - $pad - $qrSize;
                $textBoxW = $contentW;
                $textBoxH = max(120, $qrY - $marginY - $gap);
            }
        }

        // Build full line strings first (never split mid-value)
        $prepared = [];
        $nameText = '';
        foreach ($lines as $line) {
            $isPrimary = !empty($line['primary']);
            $label = (string)($line['label'] ?? '');
            $value = (string)($line['value'] ?? '');
            $text = $label !== '' ? ($label . ' ' . $value) : $value;
            $prepared[] = ['text' => $text, 'primary' => $isPrimary];
            if ($isPrimary && $nameText === '') {
                $nameText = $text;
            }
        }

        $lineGapFactor = 1.12;
        $nPrimary = 0;
        $nDetail = 0;
        foreach ($prepared as $p) {
            if ($p['primary']) {
                $nPrimary++;
            } else {
                $nDetail++;
            }
        }

        // --- Name: fill nearly full content width (1–2 print px pad each side) ---
        // At 300 dpi, 2 px ≈ 0.0067″ ≈ 7 units in our 1000/in space.
        $nameSidePad = 8; // ~2 print pixels each side
        $nameTargetW = max(60, $textBoxW - 2 * $nameSidePad);
        $minName = 56;
        $maxName = (int)round(min($textBoxH * 0.55, 280)); // height allows large name
        if ($mode === 'stack_qr') {
            $maxName = (int)round(min($textBoxH * 0.92, 140));
            $minName = 48;
        }
        $nameFs = $minName;
        if ($nameText !== '') {
            $nameFs = self::fontSizeToFillWidth($nameText, $nameTargetW, $maxName, $minName, true);
        }
        $nameWidth = $nameText !== ''
            ? self::estimateTextWidth($nameText, $nameFs, true)
            : (float)$nameTargetW;

        // --- Detail: longest line fits in ≤ 90% of the name's rendered width ---
        // (name stays the largest visual object)
        $detailMaxW = (int)max(80, min($textBoxW, (int)floor($nameWidth * 0.90)));
        $minBody = 48;
        $maxBody = (int)round(min($nameFs * 0.92, $textBoxH * 0.28, 160));

        $longestDetail = '';
        foreach ($prepared as $p) {
            if ($p['primary']) {
                continue;
            }
            $len = function_exists('mb_strlen') ? mb_strlen($p['text']) : strlen($p['text']);
            $best = function_exists('mb_strlen') ? mb_strlen($longestDetail) : strlen($longestDetail);
            if ($len > $best) {
                $longestDetail = $p['text'];
            }
        }

        // Height budget for detail lines under the name
        $nameLineH = (int)round($nameFs * $lineGapFactor);
        $detailBudgetH = max(40, $textBoxH - $nameLineH);
        $bodyFsFromHeight = $nDetail > 0
            ? (int)floor($detailBudgetH / max(1.0, $nDetail * $lineGapFactor))
            : $maxBody;
        $bodyFs = max($minBody, min($maxBody, $bodyFsFromHeight));

        if ($longestDetail !== '') {
            $bodyFs = self::fontSizeToFit($longestDetail, $detailMaxW, $bodyFs, $minBody, false);
            foreach ($prepared as $p) {
                if (!$p['primary']) {
                    $bodyFs = self::fontSizeToFit($p['text'], $detailMaxW, $bodyFs, $minBody, false);
                }
            }
        }
        // Enforce name > detail size (font px)
        if ($bodyFs >= $nameFs) {
            $bodyFs = max($minBody, (int)floor($nameFs * 0.88));
        }

        // Vertical: if total stack overflows, shrink detail first, then name
        $stackH = $nameLineH;
        foreach ($prepared as $p) {
            if (!$p['primary']) {
                $stackH += (int)round($bodyFs * $lineGapFactor);
            }
        }
        if ($stackH > $textBoxH && $stackH > 0) {
            // Prefer shrinking detail; only shrink name if still over
            $detailOnly = $stackH - (int)round($nameFs * $lineGapFactor);
            $room = $textBoxH - (int)round($nameFs * $lineGapFactor);
            if ($nDetail > 0 && $detailOnly > $room && $room > 0) {
                $bodyFs = max($minBody, (int)floor($bodyFs * ($room / $detailOnly)));
            }
            $stackH = (int)round($nameFs * $lineGapFactor);
            foreach ($prepared as $p) {
                if (!$p['primary']) {
                    $stackH += (int)round($bodyFs * $lineGapFactor);
                }
            }
            if ($stackH > $textBoxH && $stackH > 0) {
                $scale = $textBoxH / $stackH;
                $nameFs = max($minName, (int)floor($nameFs * $scale));
                $bodyFs = max($minBody, (int)floor($bodyFs * $scale));
                if ($nameText !== '') {
                    $nameFs = self::fontSizeToFillWidth($nameText, $nameTargetW, $nameFs, $minName, true);
                    $nameWidth = self::estimateTextWidth($nameText, $nameFs, true);
                    $detailMaxW = (int)max(80, min($textBoxW, (int)floor($nameWidth * 0.90)));
                }
                if ($longestDetail !== '') {
                    $bodyFs = self::fontSizeToFit($longestDetail, $detailMaxW, $bodyFs, $minBody, false);
                }
            }
            if ($bodyFs >= $nameFs) {
                $bodyFs = max($minBody, (int)floor($nameFs * 0.88));
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
            // Name: lock width to target so it spans the frame (with 1–2px pad)
            if ($isPrimary) {
                $parts[] = sprintf(
                    '<text x="%d" y="%d" text-anchor="start" font-family="%s" font-size="%d" font-weight="%s" fill="#000000" textLength="%d" lengthAdjust="spacingAndGlyphs">%s</text>',
                    $textX,
                    $y,
                    $fontFamily,
                    $fontSize,
                    $weight,
                    $nameTargetW,
                    self::xmlEsc($text)
                );
            } else {
                $maxW = $detailMaxW;
                $est = self::estimateTextWidth($text, $fontSize, false);
                if ($est > $maxW) {
                    $parts[] = sprintf(
                        '<text x="%d" y="%d" text-anchor="start" font-family="%s" font-size="%d" font-weight="%s" fill="#000000" textLength="%d" lengthAdjust="spacingAndGlyphs">%s</text>',
                        $textX,
                        $y,
                        $fontFamily,
                        $fontSize,
                        $weight,
                        $maxW,
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
