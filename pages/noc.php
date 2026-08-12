<?php
/**
 * NOC wall display — no login required (optional ?token= from Settings).
 * 3D pinned left; right side rotates Overview / Power / Zones / Cooling.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
App::boot(['light' => true]);

if (!App::isInstalled()) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(503);
    echo 'ColdAisle is not installed.';
    exit;
}

$needToken = '';
try {
    $needToken = trim((string)SettingsService::get('noc_access_token', ''));
} catch (Throwable $e) {
    $needToken = '';
}
$gotToken = (string)($_GET['token'] ?? '');
if ($needToken !== '' && !hash_equals($needToken, $gotToken)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>NOC — Forbidden</title></head><body style="font-family:sans-serif;background:#0a0f18;color:#e2e8f0;padding:2rem">';
    echo '<h1>NOC access denied</h1><p>Provide the access token in the URL: <code>?token=…</code></p>';
    echo '<p>Configure under Settings → NOC wall display.</p></body></html>';
    exit;
}

$base = App::baseUrl();
$apiUrl = App::url('api/noc.php');
if ($gotToken !== '') {
    $apiUrl .= (str_contains($apiUrl, '?') ? '&' : '?') . 'token=' . rawurlencode($gotToken);
}
$cssUrl = App::url('assets/css/noc.css') . '?v=6';
$jsUrl = App::url('assets/js/noc.js') . '?v=11';
$threeUrl = 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js';
$dcim3dUrl = App::url('assets/js/dcim-3d.js') . '?v=17';
$org = '';
try {
    $org = (string)SettingsService::get('org_name', '');
} catch (Throwable $e) {
}
$title = ($org !== '' ? $org . ' — ' : '') . 'NOC';
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title><?= App::e($title) ?></title>
  <link rel="stylesheet" href="<?= App::e($cssUrl) ?>">
  <script>
    window.ColdAisle = window.ColdAisle || {};
    window.ColdAisle.baseUrl = <?= json_encode($base, JSON_UNESCAPED_SLASHES) ?>;
    window.ColdAisleNoc = {
      apiUrl: <?= json_encode($apiUrl, JSON_UNESCAPED_SLASHES) ?>,
      token: <?= json_encode($gotToken, JSON_UNESCAPED_SLASHES) ?>,
      appVersion: <?= json_encode(App::VERSION, JSON_UNESCAPED_SLASHES) ?>,
      pollMs: 20000,
      panelRotateMs: 18000,
      sceneReloadMs: 300000,
      threeUrl: <?= json_encode($threeUrl, JSON_UNESCAPED_SLASHES) ?>,
      dcim3dUrl: <?= json_encode($dcim3dUrl, JSON_UNESCAPED_SLASHES) ?>
    };
  </script>
</head>
<body>
  <div class="noc-root" id="nocRoot">
    <header class="noc-header">
      <div>
        <h1><?= App::e($org !== '' ? $org : App::APP_NAME) ?> <span class="sub">NOC</span></h1>
        <div class="sub">Live inventory · power · cooling · environment</div>
      </div>
      <div class="noc-clock" id="nocClock">—</div>
    </header>

    <div class="noc-error" id="nocError" role="alert"></div>

    <div class="noc-3d-wrap">
      <div class="noc-3d-label">Data center · 3D</div>
      <div class="noc-3d" id="noc3d" aria-label="3D floor overview"></div>
      <!-- Glass alerts overlay the 3D view (left column) -->
      <aside class="noc-alerts-glass" id="nocAlertsGlass" aria-live="polite" aria-label="Recent alerts" hidden>
        <div class="noc-alerts-glass-head">
          <span class="noc-alerts-glass-title">Recent alerts</span>
          <span class="noc-alerts-glass-count" id="nocAlertsCount"></span>
        </div>
        <div class="noc-alerts-glass-list" id="nocAlertsList"></div>
      </aside>
    </div>

    <div class="noc-panel-wrap">
      <div class="noc-tabs" id="nocTabs" role="tablist">
        <button type="button" class="noc-tab active" data-panel="overview" role="tab">Overview</button>
        <button type="button" class="noc-tab" data-panel="power" role="tab">Power</button>
        <button type="button" class="noc-tab" data-panel="zones" role="tab">Zones</button>
        <button type="button" class="noc-tab" data-panel="cooling" role="tab">Cooling</button>
        <div class="noc-rotate-track" aria-hidden="true"><i class="noc-rotate-bar" id="nocRotateBar"></i></div>
      </div>
      <div class="noc-panel-body" id="nocPanelBody">
        <section class="noc-panel active" id="panel-overview" data-panel="overview"></section>
        <section class="noc-panel" id="panel-power" data-panel="power"></section>
        <section class="noc-panel" id="panel-zones" data-panel="zones"></section>
        <section class="noc-panel" id="panel-cooling" data-panel="cooling"></section>
      </div>
    </div>

    <footer class="noc-footer">
      <span><span class="noc-status-dot wait" id="nocDot"></span>
        <span id="nocStatus">Connecting…</span>
      </span>
      <span id="nocUpdated">Last update: —</span>
      <span id="nocPanelHint">Panel auto-rotates</span>
      <span id="nocAppVer"><?= App::e(App::APP_NAME) ?> v<?= App::e(App::VERSION) ?></span>
    </footer>
  </div>
  <script src="<?= App::e($jsUrl) ?>" defer></script>
</body>
</html>
