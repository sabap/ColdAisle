<?php
/**
 * Physical label layout for Brady BMP51-style continuous vinyl (1.50″ tape width).
 */
declare(strict_types=1);

class LabelLayoutService
{
    /** Continuous cartridge width (inches) — BMP51 max / 1.50″ vinyl. */
    public const TAPE_WIDTH_IN = 1.50;

    /** @var list<float> */
    public const LENGTH_PRESETS_IN = [2.0, 2.5, 3.0, 4.0];

    /**
     * @param array{
     *   name?:string,ip?:?string,serial?:?string,mac?:?string,
     *   url?:string,show_ip?:bool,show_serial?:bool,show_mac?:bool,show_qr?:bool,
     *   orient?:string,length_in?:float
     * } $opts
     * @return array{
     *   width_in:float,height_in:float,orient:string,length_in:float,
     *   lines:list<array{label:string,value:string,primary?:bool}>,
     *   qr_url:string,show_qr:bool,title:string,tape_width_in:float
     * }
     */
    public static function pduLabel(array $opts): array
    {
        $orient = strtolower(trim((string)($opts['orient'] ?? 'landscape')));
        if ($orient !== 'portrait' && $orient !== 'landscape') {
            $orient = 'landscape';
        }
        $length = (float)($opts['length_in'] ?? 3.0);
        if ($length < 1.25) {
            $length = 1.25;
        }
        if ($length > 12.0) {
            $length = 12.0;
        }

        // Portrait: across tape = 1.5″, along tape = length
        // Landscape: along tape = length, across tape = 1.5″
        if ($orient === 'portrait') {
            $widthIn = self::TAPE_WIDTH_IN;
            $heightIn = $length;
        } else {
            $widthIn = $length;
            $heightIn = self::TAPE_WIDTH_IN;
        }

        $name = trim((string)($opts['name'] ?? 'PDU'));
        if ($name === '') {
            $name = 'PDU';
        }
        $showIp = ($opts['show_ip'] ?? true) !== false;
        $showSerial = ($opts['show_serial'] ?? true) !== false;
        $showMac = ($opts['show_mac'] ?? true) !== false;
        $showQr = ($opts['show_qr'] ?? true) !== false;

        $lines = [];
        $lines[] = ['label' => '', 'value' => $name, 'primary' => true];

        $ip = trim((string)($opts['ip'] ?? ''));
        if ($showIp && $ip !== '') {
            $lines[] = ['label' => 'IP', 'value' => $ip];
        }
        $serial = trim((string)($opts['serial'] ?? ''));
        if ($showSerial && $serial !== '') {
            $lines[] = ['label' => 'S/N', 'value' => $serial];
        }
        $mac = trim((string)($opts['mac'] ?? ''));
        if ($showMac && $mac !== '') {
            $lines[] = ['label' => 'MAC', 'value' => self::formatMac($mac)];
        }

        $url = trim((string)($opts['url'] ?? ''));

        return [
            'width_in' => round($widthIn, 3),
            'height_in' => round($heightIn, 3),
            'orient' => $orient,
            'length_in' => round($length, 3),
            'tape_width_in' => self::TAPE_WIDTH_IN,
            'lines' => $lines,
            'qr_url' => $url,
            'show_qr' => $showQr && $url !== '',
            'title' => $name,
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
     * Full SVG document for a label (text + optional QR), sized in inches via viewBox in 1/1000 inch.
     *
     * Font sizes are fit to the available text box so thermal vinyl stays readable
     * on 1.50″ tape (and when Brady scales into a slightly smaller media preset).
     *
     * @param array<string,mixed> $layout from pduLabel()
     */
    public static function toSvg(array $layout, string $qrSvgInner = ''): string
    {
        $wIn = (float)$layout['width_in'];
        $hIn = (float)$layout['height_in'];
        // Work in thousandths of an inch (1000 units = 1″)
        $W = (int)round($wIn * 1000);
        $H = (int)round($hIn * 1000);
        $margin = max(30, (int)round(min($W, $H) * 0.04)); // ~4% short side, ≥0.03″
        $gap = max(24, (int)round(min($W, $H) * 0.03));
        $innerW = $W - 2 * $margin;
        $innerH = $H - 2 * $margin;
        $orient = (string)$layout['orient'];
        $showQr = !empty($layout['show_qr']) && $qrSvgInner !== '';
        $lines = is_array($layout['lines'] ?? null) ? $layout['lines'] : [];
        $nLines = max(1, count($lines));

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
        $textX = $margin;
        $textBoxW = $innerW;
        $textBoxH = $innerH;
        $qrX = $margin;
        $qrY = $margin;

        if ($showQr) {
            if ($orient === 'landscape') {
                // Leave ≥55% of width for text so large fonts fit; QR still scannable
                $qrSize = (int)min($innerH, (int)round($innerW * 0.36));
                $qrSize = max(320, $qrSize); // ≥0.32″
                if ($qrSize > $innerH) {
                    $qrSize = $innerH;
                }
                $qrX = $W - $margin - $qrSize;
                $qrY = $margin + (int)max(0, ($innerH - $qrSize) / 2);
                $textBoxW = max(200, $qrX - $margin - $gap);
                $textBoxH = $innerH;
            } else {
                $qrSize = (int)min($innerW, (int)round($innerH * 0.40));
                $qrSize = max(320, min($qrSize, $innerW));
                $qrX = $margin + (int)max(0, ($innerW - $qrSize) / 2);
                $qrY = $H - $margin - $qrSize;
                $textBoxW = $innerW;
                $textBoxH = max(200, $qrY - $margin - $gap);
            }
        }

        // --- Adaptive fonts (thousandths of an inch; ~14 units ≈ 1 pt) ---
        // Target: name ~11–13 pt, detail ~8–10 pt on a 1.5″-tall landscape label.
        $lineGapFactor = 1.18;
        // Budget: primary uses ~1.35× body, others 1.0×
        $weightUnits = 0.0;
        foreach ($lines as $line) {
            $weightUnits += !empty($line['primary']) ? 1.35 : 1.0;
        }
        if ($weightUnits < 1) {
            $weightUnits = 1.0;
        }
        // body size so total stack fits in textBoxH
        $bodyFs = (int)floor($textBoxH / max(1.0, $weightUnits * $lineGapFactor));
        // Soft caps by box (don't overshoot short labels)
        $maxBody = (int)round(min($textBoxH * 0.22, $textBoxW * 0.11, 130)); // ≤ ~9.4 pt-ish hard ceiling by width
        $minBody = 78;  // ~5.6 pt floor (still usable on vinyl)
        $maxName = (int)round(min($textBoxH * 0.32, $textBoxW * 0.16, 185)); // ~13 pt
        $minName = 100; // ~7.2 pt

        $bodyFs = max($minBody, min($maxBody, $bodyFs));
        $nameFs = (int)round($bodyFs * 1.35);
        $nameFs = max($minName, min($maxName, $nameFs));
        // If name+body still overflow, scale both down together
        $stackH = 0;
        foreach ($lines as $line) {
            $fs = !empty($line['primary']) ? $nameFs : $bodyFs;
            $stackH += (int)round($fs * $lineGapFactor);
        }
        if ($stackH > $textBoxH && $stackH > 0) {
            $scale = $textBoxH / $stackH;
            $nameFs = max($minName, (int)floor($nameFs * $scale));
            $bodyFs = max($minBody, (int)floor($bodyFs * $scale));
        }

        // Vertically center the text block in the text box
        $stackH = 0;
        foreach ($lines as $line) {
            $fs = !empty($line['primary']) ? $nameFs : $bodyFs;
            $stackH += (int)round($fs * $lineGapFactor);
        }
        $y = $margin + (int)max(0, ($textBoxH - $stackH) / 2) + $nameFs; // first baseline

        $fontFamily = 'Arial Black, Arial, Helvetica, sans-serif';

        foreach ($lines as $line) {
            $isPrimary = !empty($line['primary']);
            $label = (string)($line['label'] ?? '');
            $value = (string)($line['value'] ?? '');
            $fontSize = $isPrimary ? $nameFs : $bodyFs;
            // Compact field prefix (single letter) saves width for large type
            if ($label === 'IP') {
                $text = 'IP ' . $value;
            } elseif ($label === 'S/N') {
                $text = 'SN ' . $value;
            } elseif ($label === 'MAC') {
                $text = 'MAC ' . $value;
            } else {
                $text = $value;
            }
            // Fit to text box width (~0.55× font size per char for bold condensed)
            $maxChars = max(8, (int)floor($textBoxW / max(1, $fontSize * 0.52)));
            $rawLen = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
            if ($rawLen > $maxChars) {
                $cut = function_exists('mb_substr') ? mb_substr($text, 0, max(1, $maxChars - 1)) : substr($text, 0, max(1, $maxChars - 1));
                $text = $cut . '...';
            }
            $weight = $isPrimary ? '800' : '700';
            $parts[] = sprintf(
                '<text x="%d" y="%d" font-family="%s" font-size="%d" font-weight="%s" fill="#000000">%s</text>',
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
