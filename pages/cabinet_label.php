<?php
/**
 * Printable cabinet ID label(s) / QR plaques.
 *
 * Query:
 *   id=N  or  ids=1,2,3  or  room_id=N (all cabinets in room)
 *   printer, media, f_loc, f_u, f_qr, cfg=1
 *   format=html|svg|png|sheet
 *   embed=1  preview (first of batch)
 *   print=1  multi-page print (all ids)
 *   field=1  QR deep-link opens field mode on cabinet page
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/src/Services/QrCodeService.php';
require_once dirname(__DIR__) . '/src/Services/LabelLayoutService.php';
App::boot();
$user = App::requirePermission('view_cabinets');

/** @return list<int> */
function cabinet_label_parse_ids(): array
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
    $roomId = (int)($_GET['room_id'] ?? 0);
    if ($roomId > 0) {
        try {
            $rows = Database::fetchAll(
                'SELECT cabinet_id FROM cabinets WHERE room_id = ? AND is_active = 1 ORDER BY name',
                [$roomId]
            );
            foreach ($rows as $r) {
                $n = (int)$r['cabinet_id'];
                if ($n > 0) {
                    $ids[$n] = $n;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    return array_values($ids);
}

/**
 * Absolute URL for QR (phone cameras need full https:// host).
 */
function cabinet_label_deep_link(int $cabinetId, bool $fieldMode = true): string
{
    $path = 'pages/cabinets.php?id=' . $cabinetId;
    if ($fieldMode) {
        $path .= '&field=1';
    }
    return App::url($path);
}

/**
 * @return array<string,mixed>|null
 */
function cabinet_label_fetch_row(int $cabinetId): ?array
{
    return Database::fetchOne(
        'SELECT c.cabinet_id, c.name, c.u_height, c.is_active,
                r.name AS room_name, dc.name AS dc_name, cr.name AS row_name
         FROM cabinets c
         LEFT JOIN rooms r ON r.room_id = c.room_id
         LEFT JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
         LEFT JOIN cabinet_rows cr ON cr.row_id = c.row_id
         WHERE c.cabinet_id = ? AND c.is_active = 1',
        [$cabinetId]
    );
}

/**
 * @param array<string,mixed> $cab
 * @return array{layout:array<string,mixed>,svg:string,name:string,w:float,h:float,url:string,qr_svg:string}
 */
function cabinet_label_build(
    array $cab,
    string $printer,
    string $media,
    bool $showLoc,
    bool $showU,
    bool $showQr,
    bool $fieldMode
): array {
    $id = (int)$cab['cabinet_id'];
    $parts = array_filter([
        (string)($cab['dc_name'] ?? ''),
        (string)($cab['room_name'] ?? ''),
        !empty($cab['row_name']) ? ('Row ' . $cab['row_name']) : '',
    ], static fn($s) => $s !== '');
    $location = implode(' / ', $parts);
    $url = cabinet_label_deep_link($id, $fieldMode);
    $layout = LabelLayoutService::cabinetLabel([
        'name' => (string)($cab['name'] ?? 'Cabinet'),
        'location' => $location,
        'u_height' => $cab['u_height'] ?? null,
        'url' => $url,
        'show_location' => $showLoc,
        'show_u' => $showU,
        'show_qr' => $showQr,
        'printer' => $printer,
        'media' => $media,
    ]);
    $qrSvg = '';
    if (!empty($layout['show_qr'])) {
        try {
            $qrSvg = QrCodeService::svgLabel($url);
        } catch (Throwable $e) {
            App::log('Cabinet label QR: ' . $e->getMessage(), 'warning');
            $layout['show_qr'] = false;
        }
    }
    $svg = LabelLayoutService::toSvg($layout, $qrSvg);
    return [
        'layout' => $layout,
        'svg' => $svg,
        'qr_svg' => $qrSvg,
        'name' => (string)($cab['name'] ?? 'Cabinet'),
        'location' => $location,
        'url' => $url,
        'w' => (float)$layout['width_in'],
        'h' => (float)$layout['height_in'],
    ];
}

$idList = cabinet_label_parse_ids();
if (!$idList) {
    http_response_code(400);
    echo 'Cabinet id, ids, or room_id required';
    exit;
}

$printer = trim((string)($_GET['printer'] ?? LabelLayoutService::DEFAULT_PRINTER));
$media = trim((string)($_GET['media'] ?? LabelLayoutService::DEFAULT_MEDIA));
$fieldMode = !isset($_GET['field']) || $_GET['field'] === '1' || $_GET['field'] === 'true';

$showLoc = !isset($_GET['show_loc']) || $_GET['show_loc'] === '1' || $_GET['show_loc'] === 'true';
$showU = !isset($_GET['show_u']) || $_GET['show_u'] === '1' || $_GET['show_u'] === 'true';
$showQr = !isset($_GET['show_qr']) || $_GET['show_qr'] === '1' || $_GET['show_qr'] === 'true';
if (isset($_GET['cfg'])) {
    $showLoc = !empty($_GET['f_loc']);
    $showU = !empty($_GET['f_u']);
    $showQr = !empty($_GET['f_qr']);
}

/** @var list<array<string,mixed>> $cabs */
$cabs = [];
foreach ($idList as $cid) {
    $row = cabinet_label_fetch_row($cid);
    if ($row) {
        $cabs[] = $row;
    }
}
if (!$cabs) {
    http_response_code(404);
    echo 'No matching cabinets found';
    exit;
}

$built = [];
foreach ($cabs as $cab) {
    $built[] = cabinet_label_build($cab, $printer, $media, $showLoc, $showU, $showQr, $fieldMode);
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
    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $first['name']) ?: 'cabinet';
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="cabinet-label-' . $safeName . '.svg"');
    header('Cache-Control: no-store');
    echo $first['svg'];
    exit;
}

if ($format === 'png') {
    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $first['name']) ?: 'cabinet';
    $png = null;
    try {
        $png = QrCodeService::png($first['url'], 10, 2);
    } catch (Throwable $e) {
        $png = null;
    }
    if ($png === null) {
        http_response_code(501);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'PNG export requires PHP GD. Download SVG instead (?format=svg).';
        exit;
    }
    header('Content-Type: image/png');
    header('Content-Disposition: attachment; filename="cabinet-qr-' . $safeName . '.png"');
    header('Cache-Control: no-store');
    echo $png;
    exit;
}

if ($format === 'json') {
    App::json([
        'ok' => true,
        'count' => $count,
        'ids' => array_map(static fn($c) => (int)$c['cabinet_id'], $cabs),
        'width_in' => $w,
        'height_in' => $h,
        'printer' => $printer,
        'media' => $media,
        'preset_label' => $layout['preset_label'] ?? '',
        'notes' => $layout['notes'] ?? '',
        'svg' => $first['svg'],
        'url' => $first['url'],
        'names' => array_map(static fn($b) => $b['name'], $built),
    ]);
}

// Engraving / plaque sheet: grid of QR + name
if ($format === 'sheet') {
    $title = $count === 1
        ? 'Cabinet QR — ' . $first['name']
        : 'Cabinet QR sheet (' . $count . ')';
    ?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= App::e($title) ?></title>
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; padding: 12px; font: 14px/1.35 "Segoe UI", system-ui, sans-serif; color: #0f172a; background: #fff; }
    h1 { font-size: 1.1rem; margin: 0 0 4px; }
    .meta { color: #64748b; font-size: .85rem; margin: 0 0 1rem; }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 14px; }
    .cell {
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      padding: 10px;
      text-align: center;
      page-break-inside: avoid;
      break-inside: avoid;
    }
    .cell svg { width: 120px; height: 120px; display: block; margin: 0 auto 8px; }
    .cell .name { font-weight: 700; font-size: .95rem; word-break: break-word; }
    .cell .loc { font-size: .72rem; color: #475569; margin-top: 2px; }
    .toolbar { margin-bottom: 12px; }
    .toolbar button {
      font: inherit; padding: .4rem .75rem; border-radius: 6px; border: 1px solid #94a3b8;
      background: #f8fafc; cursor: pointer;
    }
    @media print {
      .toolbar { display: none !important; }
      body { padding: 0; }
      .cell { border-color: #94a3b8; }
    }
  </style>
</head>
<body>
  <div class="toolbar">
    <button type="button" onclick="window.print()">Print sheet</button>
    <span class="meta" style="margin-left:.75rem">Laser/plaque friendly · scan opens cabinet field view</span>
  </div>
  <h1><?= App::e($title) ?></h1>
  <p class="meta"><?= (int)$count ?> cabinet(s) · QR opens ColdAisle cabinet page (login if required)</p>
  <div class="grid">
    <?php foreach ($built as $b): ?>
      <div class="cell">
        <?= $b['qr_svg'] !== '' ? $b['qr_svg'] : '<span class="meta">No QR</span>' ?>
        <div class="name"><?= App::e($b['name']) ?></div>
        <?php if ($b['location'] !== ''): ?>
          <div class="loc"><?= App::e($b['location']) ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</body>
</html>
    <?php
    exit;
}

$embed = !empty($_GET['embed']);
$printOnly = !empty($_GET['print']);

// --- Embed / print-only ---
if ($embed || $printOnly):
    $title = $count === 1
        ? 'Cabinet label — ' . $first['name']
        : 'Cabinet labels (' . $count . ')';
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

// Full HTML UI (single cabinet default download page)
$safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $first['name']) ?: 'cabinet';
layout_header($count === 1 ? ('Label: ' . $first['name']) : ('Cabinet labels (' . $count . ')'), $user, 'cabinets');
$baseQ = 'ids=' . urlencode(implode(',', $idList))
    . '&printer=' . urlencode($printer)
    . '&media=' . urlencode($media)
    . '&cfg=1'
    . ($showLoc ? '&f_loc=1' : '')
    . ($showU ? '&f_u=1' : '')
    . ($showQr ? '&f_qr=1' : '')
    . ($fieldMode ? '&field=1' : '&field=0');
?>
<div class="flex-between mb-2">
    <div>
        <p class="text-muted mb-0" style="font-size:.9rem">
            Printable cabinet ID label / QR plaque.
            Scan opens the cabinet page<?= $fieldMode ? ' in field mode' : '' ?> (login if required).
        </p>
    </div>
    <div class="flex gap-1">
        <?php if ($count === 1): ?>
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/cabinets.php?id=' . (int)$cabs[0]['cabinet_id'])) ?>">← Cabinet</a>
        <?php else: ?>
            <a class="btn btn-secondary" href="<?= App::e(App::url('pages/cabinets.php')) ?>">← Cabinets</a>
        <?php endif; ?>
        <a class="btn btn-secondary" href="?<?= App::e($baseQ) ?>&format=svg">Download SVG</a>
        <a class="btn btn-secondary" href="?<?= App::e($baseQ) ?>&format=png">Download PNG (QR)</a>
        <a class="btn btn-secondary" href="?<?= App::e($baseQ) ?>&format=sheet" target="_blank">QR sheet</a>
        <a class="btn btn-primary" href="?<?= App::e($baseQ) ?>&print=1" target="_blank">Print labels</a>
    </div>
</div>

<div class="card">
    <div class="card-body" style="display:flex;flex-wrap:wrap;gap:1.5rem;align-items:flex-start">
        <div style="background:#e2e8f0;padding:1rem;border-radius:10px">
            <?= $first['svg'] ?>
        </div>
        <div style="flex:1;min-width:14rem">
            <dl class="rack-prop-list">
                <div><dt>Name</dt><dd><?= App::e($first['name']) ?></dd></div>
                <?php if ($first['location'] !== ''): ?>
                    <div><dt>Location</dt><dd><?= App::e($first['location']) ?></dd></div>
                <?php endif; ?>
                <div><dt>Size</dt><dd><?= App::e((string)$w) ?>″ × <?= App::e((string)$h) ?>″ · <?= App::e((string)($layout['preset_label'] ?? $media)) ?></dd></div>
                <div><dt>QR target</dt><dd style="word-break:break-all;font-size:.8rem"><code><?= App::e($first['url']) ?></code></dd></div>
            </dl>
            <p class="text-muted" style="font-size:.8rem;margin-top:1rem">
                <strong>Field scan tips:</strong> use the production HTTPS hostname in Settings → General
                (<code>base_url</code>) so engraved plaques keep working. Phones need network reachability
                (corp Wi‑Fi / VPN) and a valid login (local / LDAPS / Entra).
            </p>
            <?php if ($count > 1): ?>
                <p class="text-muted" style="font-size:.85rem">Batch of <strong><?= (int)$count ?></strong> cabinets — Print or QR sheet includes all.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
layout_footer();
