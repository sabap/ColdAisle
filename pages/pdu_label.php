<?php
/**
 * Printable PDU ID label — Brady BMP51.
 * Prefer media presets that match the Windows print dialog (2×2, 1.5×1, 1×1).
 *
 * Query: id, media=brady_2x2|…, show fields, format=html|svg
 * Legacy: orient=landscape|portrait&length_in= still accepted.
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

$media = trim((string)($_GET['media'] ?? LabelLayoutService::DEFAULT_MEDIA));
if ($media === '' || (!isset(LabelLayoutService::MEDIA_PRESETS[$media]) && $media !== 'custom')) {
    // Legacy
    $media = '';
}

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
$labelOpts = [
    'name' => (string)($pdu['name'] ?? 'PDU'),
    'ip' => $pdu['ip_address'] ?? null,
    'serial' => $pdu['serial_no'] ?? null,
    'mac' => $pdu['mac_address'] ?? null,
    'url' => $qrUrl,
    'show_ip' => $showIp,
    'show_serial' => $showSerial,
    'show_mac' => $showMac,
    'show_qr' => $showQr,
];
if ($media !== '' && isset(LabelLayoutService::MEDIA_PRESETS[$media])) {
    $labelOpts['media'] = $media;
} else {
    $labelOpts['orient'] = strtolower(trim((string)($_GET['orient'] ?? 'landscape')));
    $labelOpts['length_in'] = (float)($_GET['length_in'] ?? 2.0);
}

$layout = LabelLayoutService::pduLabel($labelOpts);
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
$backUrl = App::url('pages/power_pdus.php?id=' . $pduId);

function pdu_label_q(array $base, array $over = []): string
{
    $q = array_merge($base, $over);
    return App::url('pages/pdu_label.php?' . http_build_query($q));
}

$baseQuery = [
    'id' => $pduId,
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

$presetMeta = LabelLayoutService::MEDIA_PRESETS[$media] ?? null;
$isBradyDialog = $presetMeta && ($presetMeta['group'] ?? '') === 'brady';

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PDU label — <?= App::e((string)$pdu['name']) ?></title>
  <style>
    :root {
      --bg: #0f172a;
      --card: #1e293b;
      --text: #e2e8f0;
      --muted: #94a3b8;
      --accent: #38bdf8;
      --border: rgba(148,163,184,.25);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Segoe UI", system-ui, sans-serif;
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
    }
    .wrap { max-width: 960px; margin: 0 auto; padding: 1.25rem; }
    h1 { font-size: 1.25rem; margin: 0 0 .35rem; }
    .sub { color: var(--muted); font-size: .9rem; margin-bottom: 1rem; }
    .toolbar {
      display: flex; flex-wrap: wrap; gap: .65rem; align-items: flex-end;
      background: var(--card); border: 1px solid var(--border);
      border-radius: 10px; padding: .85rem 1rem; margin-bottom: 1rem;
    }
    .toolbar label { display: flex; flex-direction: column; gap: .25rem; font-size: .78rem; color: var(--muted); }
    .toolbar label.row { flex-direction: row; align-items: center; gap: .4rem; font-size: .85rem; color: var(--text); }
    select {
      background: #0f172a; color: var(--text); border: 1px solid var(--border);
      border-radius: 6px; padding: .4rem .55rem; font: inherit; min-width: 16rem;
    }
    .fields { display: flex; flex-wrap: wrap; gap: .65rem 1rem; align-items: center; }
    .actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-left: auto; }
    .btn {
      appearance: none; border: 0; border-radius: 8px; padding: .45rem .9rem;
      font: inherit; font-weight: 600; cursor: pointer; text-decoration: none;
      display: inline-flex; align-items: center; gap: .35rem;
    }
    .btn-primary { background: linear-gradient(135deg, #38bdf8, #22d3ee); color: #0f172a; }
    .btn-secondary { background: #334155; color: var(--text); }
    .btn-ghost { background: transparent; color: var(--muted); border: 1px solid var(--border); }
    .preview-stage {
      background: #334155; border-radius: 12px; padding: 1.5rem;
      display: flex; justify-content: flex-start; align-items: flex-start;
      min-height: 180px; margin-bottom: 1rem; overflow: auto;
    }
    .preview-stage .label-svg {
      background: #fff; box-shadow: 0 8px 28px rgba(0,0,0,.35);
      /* Screen: scale up for visibility but keep aspect; print uses true inches */
      max-width: min(100%, 420px);
      height: auto;
      display: block;
    }
    .size-chip {
      display: inline-block; background: rgba(56,189,248,.15); color: #7dd3fc;
      border-radius: 6px; padding: .15rem .5rem; font-weight: 650; font-size: .85rem;
    }
    .meta {
      font-size: .85rem; color: var(--muted); line-height: 1.45;
      background: var(--card); border: 1px solid var(--border);
      border-radius: 10px; padding: .85rem 1rem;
    }
    .meta code { color: #7dd3fc; word-break: break-all; }
    .meta strong { color: var(--text); }
    .warn { color: #fbbf24; }

    @media print {
      body { background: #fff !important; color: #000; margin: 0; }
      .no-print { display: none !important; }
      .wrap { max-width: none; margin: 0; padding: 0; }
      .preview-stage {
        background: none !important; padding: 0; min-height: 0; margin: 0;
        box-shadow: none; justify-content: flex-start; align-items: flex-start;
        overflow: visible;
      }
      .preview-stage .label-svg {
        box-shadow: none !important;
        max-width: none !important;
        width: <?= App::e((string)$w) ?>in !important;
        height: <?= App::e((string)$h) ?>in !important;
        margin: 0 !important;
      }
      @page {
        size: <?= App::e((string)$w) ?>in <?= App::e((string)$h) ?>in;
        margin: 0;
      }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="no-print">
      <h1>PDU identification label</h1>
      <p class="sub">
        <?= App::e((string)$pdu['name']) ?> ·
        <span class="size-chip"><?= App::e((string)$w) ?>″ × <?= App::e((string)$h) ?>″</span>
        <?php if ($presetMeta): ?>
          · <?= App::e($presetMeta['label']) ?>
        <?php endif; ?>
      </p>

      <form class="toolbar" method="get" action="">
        <input type="hidden" name="id" value="<?= (int)$pduId ?>">
        <input type="hidden" name="cfg" value="1">
        <label>Label size (match Brady print dialog)
          <select name="media" onchange="this.form.submit()">
            <optgroup label="Brady Windows dialog (use these)">
              <?php foreach (LabelLayoutService::MEDIA_PRESETS as $key => $p):
                  if (($p['group'] ?? '') !== 'brady') {
                      continue;
                  }
                  ?>
                <option value="<?= App::e($key) ?>" <?= $media === $key ? 'selected' : '' ?>>
                  <?= App::e($p['label']) ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
            <optgroup label="Continuous 1.50″ vinyl (SVG / Workstation if dialog lacks size)">
              <?php foreach (LabelLayoutService::MEDIA_PRESETS as $key => $p):
                  if (($p['group'] ?? '') !== 'continuous') {
                      continue;
                  }
                  ?>
                <option value="<?= App::e($key) ?>" <?= $media === $key ? 'selected' : '' ?>>
                  <?= App::e($p['label']) ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
          </select>
        </label>
        <div class="fields">
          <label class="row"><input type="checkbox" name="f_ip" value="1" <?= $showIp ? 'checked' : '' ?> onchange="this.form.submit()"> IP</label>
          <label class="row"><input type="checkbox" name="f_serial" value="1" <?= $showSerial ? 'checked' : '' ?> onchange="this.form.submit()"> Serial</label>
          <label class="row"><input type="checkbox" name="f_mac" value="1" <?= $showMac ? 'checked' : '' ?> onchange="this.form.submit()"> MAC</label>
          <label class="row"><input type="checkbox" name="f_qr" value="1" <?= $showQr ? 'checked' : '' ?> onchange="this.form.submit()"> QR code</label>
        </div>
        <div class="actions">
          <button type="button" class="btn btn-primary" onclick="window.print()">Print…</button>
          <a class="btn btn-secondary" href="<?= App::e(pdu_label_q($baseQuery, ['format' => 'svg'])) ?>">Download SVG</a>
          <a class="btn btn-ghost" href="<?= App::e($backUrl) ?>">Back to PDU</a>
        </div>
      </form>
    </div>

    <div class="preview-stage">
      <?= $svgPreview ?>
    </div>

    <div class="meta no-print">
      <p style="margin-top:0">
        <strong>Match the print dialog size exactly.</strong>
        Your BMP51 driver only prints cleanly on <strong>1″×1″</strong>, <strong>1.5″×1″</strong>, and <strong>2″×2″</strong>.
        Other sizes often become a huge continuous page with artwork floating in the middle.
      </p>
      <?php if ($isBradyDialog): ?>
        <p>
          Selected design is <strong><?= App::e((string)$w) ?>″ × <?= App::e((string)$h) ?>″</strong> —
          choose that same size in the Windows print dialog (e.g. <strong>2″ × 2″</strong>).
        </p>
      <?php else: ?>
        <p class="warn">
          Continuous sizes are best via <strong>Download SVG</strong> → Brady Workstation if the Windows dialog
          has no matching size (avoids the 25′ page issue).
        </p>
      <?php endif; ?>
      <p>
        Content is packed to the <strong>top-left</strong> of the label (leading edge / left of feed), not centered.
        Recommended for full name + IP + serial + MAC + QR: <strong>2″ × 2″</strong>.
      </p>
      <p>
        <strong>QR target:</strong> <code><?= App::e($qrUrl) ?></code>
      </p>
      <?php if (empty($pdu['mac_address'])): ?>
        <p>MAC is blank — SNMP Discover/Poll or Edit properties fills it for the label.</p>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
