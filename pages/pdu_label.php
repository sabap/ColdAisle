<?php
/**
 * Printable PDU ID label.
 *
 * Query:
 *   id, printer=brady_bmp51|zebra|avery|generic, media=bmp51_2x2|…,
 *   f_ip, f_serial, f_mac, f_qr, cfg=1,
 *   format=html|svg, embed=1 (minimal chrome for iframe/modal preview)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/src/Services/QrCodeService.php';
require_once dirname(__DIR__) . '/src/Services/LabelLayoutService.php';
App::boot();
$user = App::requirePermission('view_power');

$pduId = (int)($_GET['id'] ?? 0);
if ($pduId < 1) {
    http_response_code(400);
    echo 'PDU id required';
    exit;
}

$pdu = Database::fetchOne(
    'SELECT pdu_id, name, ip_address, serial_no, mac_address, is_active
     FROM pdus WHERE pdu_id = ?',
    [$pduId]
);
if (!$pdu || empty($pdu['is_active'])) {
    try {
        $pdu = Database::fetchOne(
            'SELECT pdu_id, name, ip_address, serial_no, is_active FROM pdus WHERE pdu_id = ?',
            [$pduId]
        );
        if ($pdu) {
            $pdu['mac_address'] = null;
        }
    } catch (Throwable $e) {
        $pdu = null;
    }
}
if (!$pdu || empty($pdu['is_active'])) {
    http_response_code(404);
    echo 'PDU not found';
    exit;
}

$printer = trim((string)($_GET['printer'] ?? LabelLayoutService::DEFAULT_PRINTER));
$media = trim((string)($_GET['media'] ?? LabelLayoutService::DEFAULT_MEDIA));

$showIp = !isset($_GET['show_ip']) || $_GET['show_ip'] === '1' || $_GET['show_ip'] === 'true';
$showSerial = !isset($_GET['show_serial']) || $_GET['show_serial'] === '1' || $_GET['show_serial'] === 'true';
$showMac = !isset($_GET['show_mac']) || $_GET['show_mac'] === '1' || $_GET['show_mac'] === 'true';
$showQr = !isset($_GET['show_qr']) || $_GET['show_qr'] === '1' || $_GET['show_qr'] === 'true';
if (isset($_GET['cfg'])) {
    $showIp = !empty($_GET['f_ip']);
    $showSerial = !empty($_GET['f_serial']);
    $showMac = !empty($_GET['f_mac']);
    $showQr = !empty($_GET['f_qr']);
}

$qrUrl = App::url('pages/power_pdus.php?id=' . $pduId);
$layout = LabelLayoutService::pduLabel([
    'name' => (string)($pdu['name'] ?? 'PDU'),
    'ip' => $pdu['ip_address'] ?? null,
    'serial' => $pdu['serial_no'] ?? null,
    'mac' => $pdu['mac_address'] ?? null,
    'url' => $qrUrl,
    'show_ip' => $showIp,
    'show_serial' => $showSerial,
    'show_mac' => $showMac,
    'show_qr' => $showQr,
    'printer' => $printer,
    'media' => $media,
    'orient' => $_GET['orient'] ?? null,
    'length_in' => isset($_GET['length_in']) ? (float)$_GET['length_in'] : null,
]);
$printer = (string)($layout['printer'] ?? $printer);
$media = (string)($layout['media'] ?? $media);

$qrSvg = '';
if (!empty($layout['show_qr'])) {
    try {
        $qrSvg = QrCodeService::svgLabel($qrUrl);
    } catch (Throwable $e) {
        App::log('PDU label QR: ' . $e->getMessage(), 'warning');
        $qrSvg = '';
        $layout['show_qr'] = false;
    }
}

$format = strtolower(trim((string)($_GET['format'] ?? 'html')));
if ($format === 'svg') {
    $doc = LabelLayoutService::toSvg($layout, $qrSvg);
    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$pdu['name']) ?: 'pdu';
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="pdu-label-' . $safeName . '.svg"');
    header('Cache-Control: no-store');
    echo $doc;
    exit;
}

$w = (float)$layout['width_in'];
$h = (float)$layout['height_in'];
$svgPreview = LabelLayoutService::toSvg($layout, $qrSvg);
$embed = !empty($_GET['embed']);
$printOnly = !empty($_GET['print']);

// JSON meta for modal (optional)
if ($format === 'json') {
    App::json([
        'ok' => true,
        'width_in' => $w,
        'height_in' => $h,
        'printer' => $printer,
        'media' => $media,
        'preset_label' => $layout['preset_label'] ?? '',
        'notes' => $layout['notes'] ?? '',
        'svg' => $svgPreview,
        'qr_url' => $qrUrl,
    ]);
}

function pdu_label_q(array $base, array $over = []): string
{
    $q = array_merge($base, $over);
    return App::url('pages/pdu_label.php?' . http_build_query($q));
}

$baseQuery = [
    'id' => $pduId,
    'printer' => $printer,
    'media' => $media,
    'cfg' => 1,
];
if ($showIp) {
    $baseQuery['f_ip'] = 1;
}
if ($showSerial) {
    $baseQuery['f_serial'] = 1;
}
if ($showMac) {
    $baseQuery['f_mac'] = 1;
}
if ($showQr) {
    $baseQuery['f_qr'] = 1;
}

$notes = (string)($layout['notes'] ?? '');
$presetLabel = (string)($layout['preset_label'] ?? '');

// --- Embed / print-only: minimal HTML (iframe or popup print) ---
if ($embed || $printOnly):
    ?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>PDU label — <?= App::e((string)$pdu['name']) ?></title>
  <style>
    html, body { margin: 0; padding: 0; background: #fff; }
    .preview-stage { padding: <?= $embed ? '12px' : '0' ?>; background: <?= $embed ? '#e2e8f0' : '#fff' ?>; }
    .label-svg { display: block; background: #fff; max-width: 100%; height: auto; }
    @media print {
      html, body, .preview-stage { margin: 0; padding: 0; background: #fff; }
      .label-svg {
        max-width: none !important;
        width: <?= App::e((string)$w) ?>in !important;
        height: <?= App::e((string)$h) ?>in !important;
      }
      @page { size: <?= App::e((string)$w) ?>in <?= App::e((string)$h) ?>in; margin: 0; }
    }
  </style>
  <?php if ($printOnly): ?>
  <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 200); });</script>
  <?php endif; ?>
</head>
<body>
  <div class="preview-stage"><?= $svgPreview ?></div>
</body>
</html>
    <?php
    exit;
endif;

// --- Full page (fallback / direct link) ---
$backUrl = App::url('pages/power_pdus.php?id=' . $pduId);
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PDU label — <?= App::e((string)$pdu['name']) ?></title>
  <style>
    body { font-family: "Segoe UI", system-ui, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; padding: 1.25rem; }
    a { color: #38bdf8; }
    .label-svg { background: #fff; max-width: min(100%, 420px); height: auto; }
    @media print {
      body { background: #fff; padding: 0; }
      .no-print { display: none !important; }
      .label-svg { max-width: none; width: <?= App::e((string)$w) ?>in; height: <?= App::e((string)$h) ?>in; }
      @page { size: <?= App::e((string)$w) ?>in <?= App::e((string)$h) ?>in; margin: 0; }
    }
  </style>
</head>
<body>
  <p class="no-print"><a href="<?= App::e($backUrl) ?>">← Back to PDU</a>
    · <?= App::e((string)$w) ?>″ × <?= App::e((string)$h) ?>″
    · <a href="<?= App::e(pdu_label_q($baseQuery, ['format' => 'svg'])) ?>">Download SVG</a>
    · <button type="button" onclick="window.print()">Print</button>
  </p>
  <?= $svgPreview ?>
  <?php if ($notes !== ''): ?><p class="no-print" style="color:#94a3b8;font-size:.85rem"><?= App::e($notes) ?></p><?php endif; ?>
</body>
</html>
