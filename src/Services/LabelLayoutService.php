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
     * @param array<string,mixed> $layout from pduLabel()
     */
    public static function toSvg(array $layout, string $qrSvgInner = ''): string
    {
        $wIn = (float)$layout['width_in'];
        $hIn = (float)$layout['height_in'];
        // Work in thousandths of an inch
        $W = (int)round($wIn * 1000);
        $H = (int)round($hIn * 1000);
        $margin = 40; // 0.04″
        $innerW = $W - 2 * $margin;
        $innerH = $H - 2 * $margin;
        $orient = (string)$layout['orient'];
        $showQr = !empty($layout['show_qr']) && $qrSvgInner !== '';

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
        $textY = $margin;
        $qrX = $margin;
        $qrY = $margin;

        if ($showQr) {
            if ($orient === 'landscape') {
                $qrSize = (int)min($innerH, (int)round($innerW * 0.42));
                $qrX = $W - $margin - $qrSize;
                $qrY = $margin + (int)max(0, ($innerH - $qrSize) / 2);
            } else {
                $qrSize = (int)min($innerW, (int)round($innerH * 0.45));
                $qrX = $margin + (int)max(0, ($innerW - $qrSize) / 2);
                $qrY = $H - $margin - $qrSize;
            }
        }

        // Text
        $y = $textY + 90;
        $lines = $layout['lines'] ?? [];
        foreach ($lines as $i => $line) {
            $isPrimary = !empty($line['primary']);
            $label = (string)($line['label'] ?? '');
            $value = (string)($line['value'] ?? '');
            $fontSize = $isPrimary ? 110 : 72;
            if ($orient === 'portrait' && count($lines) > 3) {
                $fontSize = $isPrimary ? 95 : 64;
            }
            $text = $label !== '' ? ($label . '  ' . $value) : $value;
            $text = self::xmlEsc($text);
            // Truncate visually with font-size; hard cap characters for long names
            $rawLen = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
            if ($rawLen > 42) {
                $val = (string)($line['value'] ?? $text);
                $cut = function_exists('mb_substr') ? mb_substr($val, 0, 40) : substr($val, 0, 40);
                $text = self::xmlEsc($cut . '...');
            }
            $weight = $isPrimary ? '700' : '500';
            $parts[] = sprintf(
                '<text x="%d" y="%d" font-family="Segoe UI, Arial, Helvetica, sans-serif" font-size="%d" font-weight="%s" fill="#000000">%s</text>',
                $textX,
                $y,
                $fontSize,
                $weight,
                $text
            );
            $y += (int)round($fontSize * 1.25);
            if ($showQr && $orient === 'portrait' && $y > $qrY - 20) {
                break;
            }
            if ($orient === 'landscape' && $y > $H - $margin) {
                break;
            }
        }

        if ($showQr && $qrSize > 0) {
            // Embed QR: strip outer svg wrapper, scale into qrSize box
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
