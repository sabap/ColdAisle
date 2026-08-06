<?php
/**
 * Physical label layout for Brady BMP51 (Windows dialog sizes + continuous vinyl).
 *
 * Primary presets match sizes that typically work in the BMP51 Windows print dialog:
 *   2″×2″, 1.5″×1″, 1″×1″.
 * Continuous 1.50″-wide layouts remain available for SVG / Brady Workstation.
 */
declare(strict_types=1);

class LabelLayoutService
{
    /** Continuous cartridge width (inches) — BMP51 max / 1.50″ vinyl. */
    public const TAPE_WIDTH_IN = 1.50;

    /**
     * Media presets (width × height in inches as printed on the page).
     * Keys are stable for URLs.
     *
     * @var array<string,array{w:float,h:float,label:string,group:string}>
     */
    public const MEDIA_PRESETS = [
        // --- Sizes that match common BMP51 Windows dialog options ---
        'brady_2x2' => [
            'w' => 2.0,
            'h' => 2.0,
            'label' => '2″ × 2″ — Brady dialog (recommended)',
            'group' => 'brady',
        ],
        'brady_1_5x1' => [
            'w' => 1.5,
            'h' => 1.0,
            'label' => '1.5″ × 1″ — Brady dialog',
            'group' => 'brady',
        ],
        'brady_1x1' => [
            'w' => 1.0,
            'h' => 1.0,
            'label' => '1″ × 1″ — Brady dialog (QR + name)',
            'group' => 'brady',
        ],
        // --- Continuous 1.50″ tape (use SVG if Windows only offers die-cut sizes) ---
        'cont_h2' => [
            'w' => 2.0,
            'h' => 1.5,
            'label' => 'Continuous 2″ long × 1.50″ (horizontal)',
            'group' => 'continuous',
        ],
        'cont_h3' => [
            'w' => 3.0,
            'h' => 1.5,
            'label' => 'Continuous 3″ long × 1.50″ (horizontal)',
            'group' => 'continuous',
        ],
        'cont_v2' => [
            'w' => 1.5,
            'h' => 2.0,
            'label' => 'Continuous 1.50″ × 2″ long (vertical)',
            'group' => 'continuous',
        ],
        'cont_v3' => [
            'w' => 1.5,
            'h' => 3.0,
            'label' => 'Continuous 1.50″ × 3″ long (vertical)',
            'group' => 'continuous',
        ],
    ];

    /** Default for new label UI */
    public const DEFAULT_MEDIA = 'brady_2x2';

    /** @deprecated use MEDIA_PRESETS — kept for old ?length_in= links */
    public const LENGTH_PRESETS_IN = [2.0, 2.5, 3.0, 4.0];

    /**
     * @param array{
     *   name?:string,ip?:?string,serial?:?string,mac?:?string,
     *   url?:string,show_ip?:bool,show_serial?:bool,show_mac?:bool,show_qr?:bool,
     *   media?:string,orient?:string,length_in?:float
     * } $opts
     * @return array{
     *   width_in:float,height_in:float,media:string,orient:string,length_in:float,
     *   lines:list<array{label:string,value:string,primary?:bool}>,
     *   qr_url:string,show_qr:bool,title:string,tape_width_in:float,layout_mode:string
     * }
     */
    public static function pduLabel(array $opts): array
    {
        $media = trim((string)($opts['media'] ?? ''));
        if ($media === '' || !isset(self::MEDIA_PRESETS[$media])) {
            // Legacy orient + length_in
            $orient = strtolower(trim((string)($opts['orient'] ?? 'landscape')));
            if ($orient !== 'portrait' && $orient !== 'landscape') {
                $orient = 'landscape';
            }
            $length = (float)($opts['length_in'] ?? 2.0);
            if ($length < 1.0) {
                $length = 1.0;
            }
            if ($length > 12.0) {
                $length = 12.0;
            }
            if ($orient === 'portrait') {
                $widthIn = self::TAPE_WIDTH_IN;
                $heightIn = $length;
                $media = 'cont_v' . (abs($length - 3.0) < 0.01 ? '3' : '2');
                if (!isset(self::MEDIA_PRESETS[$media])) {
                    $media = 'custom';
                }
            } else {
                $widthIn = $length;
                $heightIn = self::TAPE_WIDTH_IN;
                $media = 'cont_h' . (abs($length - 3.0) < 0.01 ? '3' : '2');
                if (!isset(self::MEDIA_PRESETS[$media])) {
                    $media = 'custom';
                }
            }
        } else {
            $preset = self::MEDIA_PRESETS[$media];
            $widthIn = (float)$preset['w'];
            $heightIn = (float)$preset['h'];
            $orient = $widthIn >= $heightIn ? 'landscape' : 'portrait';
            $length = max($widthIn, $heightIn);
        }

        $name = trim((string)($opts['name'] ?? 'PDU'));
        if ($name === '') {
            $name = 'PDU';
        }
        $showIp = ($opts['show_ip'] ?? true) !== false;
        $showSerial = ($opts['show_serial'] ?? true) !== false;
        $showMac = ($opts['show_mac'] ?? true) !== false;
        $showQr = ($opts['show_qr'] ?? true) !== false;

        // Tiny 1×1: name + QR only (detail lines unreadable)
        $tiny = ($widthIn <= 1.05 && $heightIn <= 1.05);

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

        // Layout mode for SVG packing
        $layoutMode = 'side'; // text | QR side-by-side
        if ($tiny) {
            $layoutMode = 'stack_qr'; // name then QR
        } elseif ($heightIn >= $widthIn * 0.95 && $heightIn >= 1.75) {
            $layoutMode = 'stack'; // text top, QR bottom (square / tall)
        } elseif ($heightIn <= 1.15) {
            $layoutMode = 'side'; // short strip: text left, QR right
        } else {
            $layoutMode = $widthIn > $heightIn ? 'side' : 'stack';
        }

        return [
            'width_in' => round($widthIn, 3),
            'height_in' => round($heightIn, 3),
            'media' => $media,
            'orient' => $orient,
            'length_in' => round($length, 3),
            'tape_width_in' => self::TAPE_WIDTH_IN,
            'lines' => $lines,
            'qr_url' => $url,
            'show_qr' => $showQr && $url !== '',
            'title' => $name,
            'layout_mode' => $layoutMode,
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
     * Full SVG document — content packed to top-left (leading edge / left of feed).
     *
     * @param array<string,mixed> $layout from pduLabel()
     */
    public static function toSvg(array $layout, string $qrSvgInner = ''): string
    {
        $wIn = (float)$layout['width_in'];
        $hIn = (float)$layout['height_in'];
        $W = (int)round($wIn * 1000);
        $H = (int)round($hIn * 1000);
        // Tighter margins on small media
        $margin = max(28, (int)round(min($W, $H) * 0.045));
        $gap = max(20, (int)round(min($W, $H) * 0.035));
        $innerW = $W - 2 * $margin;
        $innerH = $H - 2 * $margin;
        $mode = (string)($layout['layout_mode'] ?? 'side');
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
        $qrX = $margin;
        $qrY = $margin;
        $textX = $margin;
        $textBoxW = $innerW;
        $textBoxH = $innerH;

        if ($showQr) {
            if ($mode === 'side') {
                // Short horizontal strip (e.g. 1.5×1): QR on right, text left — top aligned
                $qrSize = (int)min($innerH, (int)round($innerW * 0.40));
                $qrSize = max(280, min($qrSize, $innerH));
                $qrX = $W - $margin - $qrSize;
                $qrY = $margin; // top, not vertically centered
                $textBoxW = max(180, $qrX - $margin - $gap);
                $textBoxH = $innerH;
            } elseif ($mode === 'stack_qr') {
                // 1×1: small name on top, QR fills rest
                $nameBudget = (int)round($innerH * 0.22);
                $qrSize = (int)min($innerW, $innerH - $nameBudget - $gap);
                $qrSize = max(200, $qrSize);
                $qrX = $margin + (int)max(0, ($innerW - $qrSize) / 2);
                $qrY = $H - $margin - $qrSize;
                $textBoxW = $innerW;
                $textBoxH = max(80, $qrY - $margin - $gap);
            } else {
                // stack (2×2 and tall vertical): text top-left, QR bottom-left (leading/top when fed)
                $qrSize = (int)min($innerW * 0.92, $innerH * 0.48);
                $qrSize = max(360, min($qrSize, $innerW));
                $qrX = $margin; // left, not centered — matches feed edge
                $qrY = $H - $margin - $qrSize;
                $textBoxW = $innerW;
                $textBoxH = max(160, $qrY - $margin - $gap);
            }
        }

        // Fonts: fit into text box; pack from TOP (no vertical centering)
        $lineGapFactor = 1.12;
        $weightUnits = 0.0;
        foreach ($lines as $line) {
            $weightUnits += !empty($line['primary']) ? 1.30 : 1.0;
        }
        if ($weightUnits < 1) {
            $weightUnits = 1.0;
        }

        $bodyFs = (int)floor($textBoxH / max(1.0, $weightUnits * $lineGapFactor));
        // Arial Black is wide — cap by width so lines don't clip
        $maxBody = (int)round(min($textBoxH * 0.28, $textBoxW * 0.095, 145));
        $minBody = 70;
        $maxName = (int)round(min($textBoxH * 0.38, $textBoxW * 0.14, 200));
        $minName = 90;

        if ($mode === 'stack_qr') {
            $maxName = (int)round(min($textBoxH * 0.85, $textBoxW * 0.12, 120));
            $minName = 70;
            $maxBody = $maxName;
            $minBody = $minName;
        }

        $bodyFs = max($minBody, min($maxBody, $bodyFs));
        $nameFs = (int)round($bodyFs * 1.28);
        $nameFs = max($minName, min($maxName, $nameFs));

        $stackH = 0;
        foreach ($lines as $line) {
            $fs = !empty($line['primary']) ? $nameFs : $bodyFs;
            $stackH += (int)round($fs * $lineGapFactor);
        }
        if ($stackH > $textBoxH && $stackH > 0) {
            $scale = $textBoxH / $stackH;
            $nameFs = max((int)($minName * 0.85), (int)floor($nameFs * $scale));
            $bodyFs = max((int)($minBody * 0.85), (int)floor($bodyFs * $scale));
        }

        // TOP-LEFT pack (not centered) — first baseline just below top margin
        $y = $margin + $nameFs;
        $fontFamily = 'Arial, Helvetica, sans-serif'; // regular Arial is narrower / more reliable on thermal

        foreach ($lines as $line) {
            $isPrimary = !empty($line['primary']);
            $label = (string)($line['label'] ?? '');
            $value = (string)($line['value'] ?? '');
            $fontSize = $isPrimary ? $nameFs : $bodyFs;
            if ($label !== '') {
                $text = $label . ' ' . $value;
            } else {
                $text = $value;
            }
            // Bold condensed estimate: ~0.60 of font size per char (was 0.52 → overflow)
            $charW = $isPrimary ? 0.62 : 0.58;
            $maxChars = max(6, (int)floor($textBoxW / max(1.0, $fontSize * $charW)));
            $rawLen = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
            if ($rawLen > $maxChars) {
                $cutN = max(1, $maxChars - 2);
                $cut = function_exists('mb_substr') ? mb_substr($text, 0, $cutN) : substr($text, 0, $cutN);
                $text = $cut . '..';
            }
            $weight = $isPrimary ? '700' : '600';
            // text-anchor start = left; explicit x at left margin
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
