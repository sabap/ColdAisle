<?php
/**
 * Printable PDU ID label (Brady BMP51 continuous 1.50″ vinyl friendly).
 * Query: id, orient=landscape|portrait, length_in, show_ip, show_serial, show_mac, show_qr, format=html|svg
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
    // Column may not exist yet on first hit before schema ensure — retry without mac
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

$orient = strtolower(trim((string)($_GET['orient'] ?? 'landscape')));
if ($orient !== 'portrait') {
    $orient = 'landscape';
}
$lengthIn = (float)($_GET['length_in'] ?? 3.0);
if (isset($_GET['preset']) && $_GET['preset'] !== 'custom') {
    $preset = (float)$_GET['preset'];
    if (in_array($preset, LabelLayoutService::LENGTH_PRESETS_IN, true)) {
        $lengthIn = $preset;
    }
}

$showIp = !isset($_GET['show_ip']) || $_GET['show_ip'] === '1' || $_GET['show_ip'] === 'true';
$showSerial = !isset($_GET['show_serial']) || $_GET['show_serial'] === '1' || $_GET['show_serial'] === 'true';
$showMac = !isset($_GET['show_mac']) || $_GET['show_mac'] === '1' || $_GET['show_mac'] === 'true';
$showQr = !isset($_GET['show_qr']) || $_GET['show_qr'] === '1' || $_GET['show_qr'] === 'true';

// Checkboxes when form posts as GET without unchecked keys
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
    'orient' => $orient,
    'length_in' => $lengthIn,
]);

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
    'orient' => $orient,
    'length_in' => $lengthIn,
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
      display: flex; flex-wrap: wrap; gap: .5rem; align-items: flex-end;
      background: var(--card); border: 1px solid var(--border);
      border-radius: 10px; padding: .85rem 1rem; margin-bottom: 1rem;
    }
    .toolbar label { display: flex; flex-direction: column; gap: .25rem; font-size: .78rem; color: var(--muted); }
    .toolbar label.row { flex-direction: row; align-items: center; gap: .4rem; font-size: .85rem; color: var(--text); }
    select, input[type="number"] {
      background: #0f172a; color: var(--text); border: 1px solid var(--border);
      border-radius: 6px; padding: .35rem .5rem; font: inherit;
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
      display: flex; justify-content: center; align-items: center;
      min-height: 220px; margin-bottom: 1rem;
    }
    .preview-stage .label-svg {
      background: #fff; box-shadow: 0 8px 28px rgba(0,0,0,.35);
      /* Scale for screen; print uses physical size */
      max-width: min(100%, 520px);
      height: auto;
    }
    .meta {
      font-size: .85rem; color: var(--muted); line-height: 1.45;
      background: var(--card); border: 1px solid var(--border);
      border-radius: 10px; padding: .85rem 1rem;
    }
    .meta code { color: #7dd3fc; word-break: break-all; }
    .meta strong { color: var(--text); }

    @media print {
      body { background: #fff; color: #000; }
      .no-print { display: none !important; }
      .wrap { max-width: none; margin: 0; padding: 0; }
      .preview-stage {
        background: none; padding: 0; min-height: 0;
        box-shadow: none; justify-content: flex-start;
      }
      .preview-stage .label-svg {
        box-shadow: none;
        max-width: none;
        width: <?= App::e((string)$w) ?>in !important;
        height: <?= App::e((string)$h) ?>in !important;
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
        <?= App::e((string)$pdu['name']) ?> · sized for <strong>Brady BMP51</strong> continuous
        <strong><?= App::e((string)LabelLayoutService::TAPE_WIDTH_IN) ?>″</strong> vinyl
        (<?= App::e((string)$w) ?>″ × <?= App::e((string)$h) ?>″)
      </p>

      <form class="toolbar" method="get" action="">
        <input type="hidden" name="id" value="<?= (int)$pduId ?>">
        <input type="hidden" name="cfg" value="1">
        <label>Orientation
          <select name="orient" onchange="this.form.submit()">
            <option value="landscape" <?= $orient === 'landscape' ? 'selected' : '' ?>>Horizontal (along tape)</option>
            <option value="portrait" <?= $orient === 'portrait' ? 'selected' : '' ?>>Vertical (along tape)</option>
          </select>
        </label>
        <label>Length along tape
          <select name="length_in" onchange="this.form.submit()">
            <?php foreach (LabelLayoutService::LENGTH_PRESETS_IN as $preset): ?>
              <option value="<?= App::e((string)$preset) ?>" <?= abs($lengthIn - $preset) < 0.01 ? 'selected' : '' ?>>
                <?= App::e(rtrim(rtrim(number_format($preset, 1, '.', ''), '0'), '.')) ?>″
              </option>
            <?php endforeach; ?>
            <?php
            $isCustom = true;
            foreach (LabelLayoutService::LENGTH_PRESETS_IN as $preset) {
                if (abs($lengthIn - $preset) < 0.01) {
                    $isCustom = false;
                    break;
                }
            }
            if ($isCustom): ?>
              <option value="<?= App::e((string)$lengthIn) ?>" selected>Custom <?= App::e((string)$lengthIn) ?>″</option>
            <?php endif; ?>
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
      <?= $svgPreview /* trusted SVG from our services */ ?>
    </div>

    <div class="meta no-print">
      <p style="margin-top:0">
        <strong>Print tips:</strong> Choose the BMP51 (or continuous 1.50″ cartridge) in the Windows print dialog.
        Set page size to <strong><?= App::e((string)$w) ?>″ × <?= App::e((string)$h) ?>″</strong> if the driver asks.
        If the driver ignores custom size, use <strong>Download SVG</strong> and import into Brady Workstation / LabelMark.
      </p>
      <p>
        <strong>QR target:</strong> <code><?= App::e($qrUrl) ?></code><br>
        Scanning opens this PDU in ColdAisle (login required if not already signed in). Phones need network/VPN reachability to the server.
      </p>
      <?php if (empty($pdu['mac_address'])): ?>
        <p>MAC is blank — add it under <strong>Edit properties</strong> to print it on the label.</p>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
