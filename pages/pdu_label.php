<?php
/**
 * Printable PDU ID label(s).
 *
 * Query:
 *   id=N  or  ids=1,2,3
 *   printer, media, f_ip, f_serial, f_mac, f_qr, cfg=1
 *   format=html|svg  (svg = first PDU only)
 *   embed=1  preview (first of batch)
 *   print=1  multi-page print (all ids)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/src/Services/QrCodeService.php';
require_once dirname(__DIR__) . '/src/Services/LabelLayoutService.php';
App::boot();
$user = App::requirePermission('view_power');

/** @return list<int> */
function pdu_label_parse_ids(): array
{
    $ids = [];
    if (!empty($_GET['ids'])) {
        foreach (preg_split('/[,\s]+/', (string)$_GET['ids']) ?: [] as $part) {
            $n = (int)$part;
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }
    }
    $one = (int)($_GET['id'] ?? 0);
    if ($one > 0) {
        $ids[$one] = $one;
    }
    return array_values($ids);
}

/**
 * @return array<string,mixed>|null
 */
function pdu_label_fetch_row(int $pduId): ?array
{
    $pdu = Database::fetchOne(
        'SELECT pdu_id, name, ip_address, serial_no, mac_address, is_active
         FROM pdus WHERE pdu_id = ? AND is_active = 1',
        [$pduId]
    );
    if ($pdu) {
        return $pdu;
    }
    try {
        $pdu = Database::fetchOne(
            'SELECT pdu_id, name, ip_address, serial_no, is_active FROM pdus WHERE pdu_id = ? AND is_active = 1',
            [$pduId]
        );
        if ($pdu) {
            $pdu['mac_address'] = null;
            return $pdu;
        }
    } catch (Throwable $e) {
        // ignore
    }
    return null;
}

/**
 * @param array<string,mixed> $pdu
 * @return array{layout:array<string,mixed>,svg:string,name:string,w:float,h:float}
 */
function pdu_label_build(
    array $pdu,
    string $printer,
    string $media,
    bool $showIp,
    bool $showSerial,
    bool $showMac,
    bool $showQr
): array {
    $pduId = (int)$pdu['pdu_id'];
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
    $qrSvg = '';
    if (!empty($layout['show_qr'])) {
        try {
            $qrSvg = QrCodeService::svgLabel($qrUrl);
        } catch (Throwable $e) {
            App::log('PDU label QR: ' . $e->getMessage(), 'warning');
            $layout['show_qr'] = false;
        }
    }
    $svg = LabelLayoutService::toSvg($layout, $qrSvg);
    return [
        'layout' => $layout,
        'svg' => $svg,
        'name' => (string)($pdu['name'] ?? 'PDU'),
        'w' => (float)$layout['width_in'],
        'h' => (float)$layout['height_in'],
    ];
}

$idList = pdu_label_parse_ids();
if (!$idList) {
    http_response_code(400);
    echo 'PDU id or ids required';
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

/** @var list<array<string,mixed>> $pdus */
$pdus = [];
foreach ($idList as $pid) {
    $row = pdu_label_fetch_row($pid);
    if ($row) {
        $pdus[] = $row;
    }
}
if (!$pdus) {
    http_response_code(404);
    echo 'No matching PDUs found';
    exit;
}

$built = [];
foreach ($pdus as $pdu) {
    $built[] = pdu_label_build($pdu, $printer, $media, $showIp, $showSerial, $showMac, $showQr);
}

$first = $built[0];
$layout = $first['layout'];
$printer = (string)($layout['printer'] ?? $printer);
$media = (string)($layout['media'] ?? $media);
$w = $first['w'];
$h = $first['h'];
$count = count($built);

$format = strtolower(trim((string)($_GET['format'] ?? 'html')));
if ($format === 'svg') {
    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $first['name']) ?: 'pdu';
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="pdu-label-' . $safeName . '.svg"');
    header('Cache-Control: no-store');
    echo $first['svg'];
    exit;
}

if ($format === 'json') {
    App::json([
        'ok' => true,
        'count' => $count,
        'ids' => array_map(static fn($p) => (int)$p['pdu_id'], $pdus),
        'width_in' => $w,
        'height_in' => $h,
        'printer' => $printer,
        'media' => $media,
        'preset_label' => $layout['preset_label'] ?? '',
        'notes' => $layout['notes'] ?? '',
        'svg' => $first['svg'],
        'names' => array_map(static fn($b) => $b['name'], $built),
    ]);
}

$embed = !empty($_GET['embed']);
$printOnly = !empty($_GET['print']);

// --- Embed / print-only ---
if ($embed || $printOnly):
    $title = $count === 1
        ? 'PDU label — ' . $first['name']
        : 'PDU labels (' . $count . ')';
    ?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= App::e($title) ?></title>
  <style>
    html, body { margin: 0; padding: 0; background: #fff; }
    .preview-stage { padding: <?= $embed ? '12px' : '0' ?>; background: <?= $embed ? '#e2e8f0' : '#fff' ?>; }
    .label-page { background: #fff; }
    .label-svg { display: block; background: #fff; max-width: 100%; height: auto; }
    .embed-meta { font: 13px/1.4 "Segoe UI", system-ui, sans-serif; color: #475569; margin: 0 0 8px; }
    @media print {
      html, body, .preview-stage { margin: 0; padding: 0; background: #fff; }
      .embed-meta { display: none !important; }
      .label-page {
        page-break-after: always;
        break-after: page;
        margin: 0;
        padding: 0;
      }
      .label-page:last-child {
        page-break-after: auto;
        break-after: auto;
      }
      .label-svg {
        max-width: none !important;
        width: <?= App::e((string)$w) ?>in !important;
        height: <?= App::e((string)$h) ?>in !important;
      }
      @page { size: <?= App::e((string)$w) ?>in <?= App::e((string)$h) ?>in; margin: 0; }
    }
  </style>
  <?php if ($printOnly): ?>
  <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });</script>
  <?php endif; ?>
</head>
<body>
  <?php if ($embed && $count > 1): ?>
    <p class="embed-meta">Preview: <strong><?= App::e($first['name']) ?></strong>
      · printing will include <strong><?= (int)$count ?></strong> labels</p>
  <?php endif; ?>
  <?php if ($embed): ?>
    <div class="preview-stage"><?= $first['svg'] ?></div>
  <?php else: ?>
    <?php foreach ($built as $b): ?>
      <div class="label-page"><?= $b['svg'] ?></div>
    <?php endforeach; ?>
  <?php endif; ?>
</body>
</html>
    <?php
    exit;
endif;

// --- Full page fallback ---
$backUrl = count($pdus) === 1
    ? App::url('pages/power_pdus.php?id=' . (int)$pdus[0]['pdu_id'])
    : App::url('pages/power_pdus.php');
$idsParam = implode(',', array_map(static fn($p) => (int)$p['pdu_id'], $pdus));
$printQ = http_build_query(array_filter([
    'ids' => $idsParam,
    'printer' => $printer,
    'media' => $media,
    'cfg' => 1,
    'f_ip' => $showIp ? 1 : null,
    'f_serial' => $showSerial ? 1 : null,
    'f_mac' => $showMac ? 1 : null,
    'f_qr' => $showQr ? 1 : null,
    'print' => 1,
]));
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= App::e($count === 1 ? $first['name'] : ($count . ' labels')) ?></title>
  <style>
    body { font-family: "Segoe UI", system-ui, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; padding: 1.25rem; }
    a { color: #38bdf8; }
    .label-svg { background: #fff; max-width: min(100%, 420px); height: auto; }
  </style>
</head>
<body>
  <p>
    <a href="<?= App::e($backUrl) ?>">← Back</a>
    · <?= (int)$count ?> label(s)
    · <a href="<?= App::e(App::url('pages/pdu_label.php?' . $printQ)) ?>">Print…</a>
  </p>
  <?= $first['svg'] ?>
</body>
</html>
