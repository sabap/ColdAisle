<?php
/**
 * Experimental overlay NOC wall — same live API as production NOC, different chrome.
 * Production pages/noc.php is unchanged.
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
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Lab NOC — Forbidden</title></head><body style="font-family:sans-serif;background:#0a0f18;color:#e2e8f0;padding:2rem">';
    echo '<h1>Lab NOC access denied</h1><p>Provide the access token: <code>?token=…</code></p>';
    echo '<p>Same token as Settings → NOC wall display.</p></body></html>';
    exit;
}

$base = App::baseUrl();
$apiUrl = App::url('api/noc.php?scene=1');
if ($gotToken !== '') {
    $apiUrl .= (str_contains($apiUrl, '?') ? '&' : '?') . 'token=' . rawurlencode($gotToken);
}
$cssUrl = App::url('assets/css/dash-lab.css') . '?v=2';
$jsUrl = App::url('assets/js/dash-lab.js') . '?v=2';
$threeUrl = 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js';
$dcim3dUrl = App::url('assets/js/dcim-3d.js') . '?v=39';
$org = '';
try {
    $org = (string)SettingsService::get('org_name', '');
} catch (Throwable $e) {
}
$title = ($org !== '' ? $org . ' — ' : '') . 'Lab NOC';
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <meta name="theme-color" content="#05080f">
  <title><?= App::e($title) ?></title>
  <link rel="stylesheet" href="<?= App::e($cssUrl) ?>">
  <script>
    window.ColdAisle = window.ColdAisle || {};
    window.ColdAisle.baseUrl = <?= json_encode($base, JSON_UNESCAPED_SLASHES) ?>;
    window.ColdAisleLab = {
      apiUrl: <?= json_encode($apiUrl, JSON_UNESCAPED_SLASHES) ?>,
      pollMs: 20000,
      autoRotate: true,
      threeUrl: <?= json_encode($threeUrl, JSON_UNESCAPED_SLASHES) ?>,
      dcim3dUrl: <?= json_encode($dcim3dUrl, JSON_UNESCAPED_SLASHES) ?>,
      logoUrl: <?= json_encode(App::url('assets/img/logo.svg'), JSON_UNESCAPED_SLASHES) ?>
    };
  </script>
</head>
<body class="lab-noc-page">
  <div class="lab-overlay-root">
    <nav class="lab-rail" aria-label="Lab views">
      <div class="lab-core" title="Link"></div>
      <button type="button" class="lab-rail-btn is-active" data-panel="overview" title="Overview">
        <span class="lab-rail-stack">▣<small>All</small></span>
      </button>
      <button type="button" class="lab-rail-btn" data-panel="thermal" title="Thermal">
        <span class="lab-rail-stack">🌡<small>Temp</small></span>
      </button>
      <button type="button" class="lab-rail-btn" data-panel="power" title="Power">
        <span class="lab-rail-stack">⚡<small>Power</small></span>
      </button>
      <button type="button" class="lab-rail-btn" data-panel="network" title="Inventory">
        <span class="lab-rail-stack">🖥<small>Inv</small></span>
      </button>
    </nav>
    <header class="lab-top">
      <div style="display:flex;align-items:baseline;flex-wrap:wrap">
        <h1>ColdAisle // Command</h1>
        <span class="lab-kicker">Wall HUD</span>
      </div>
      <div style="display:flex;align-items:center;gap:1rem">
        <span class="lab-live"><i></i> Live link</span>
        <a href="<?= App::e(App::url('pages/noc.php') . ($gotToken !== '' ? ('?token=' . rawurlencode($gotToken)) : '')) ?>">Exit HUD</a>
        <span class="lab-clock" id="labClock">—</span>
      </div>
    </header>
    <div class="lab-stage">
      <div class="lab-3d" id="lab3d" aria-label="Experimental 3D hall"></div>
      <div class="lab-hud" aria-hidden="true">
        <span class="c tl"></span><span class="c tr"></span>
        <span class="c bl"></span><span class="c br"></span>
        <div class="lab-hex"></div>
        <div class="lab-scan"></div>
        <div class="lab-vignette"></div>
        <div class="lab-reticle"></div>
      </div>
      <div class="lab-chips" id="labChips"></div>
      <div class="lab-cam" role="group" aria-label="Camera">
        <button type="button" data-cam="orbit" class="is-active">Orbit</button>
        <button type="button" data-cam="aisle">Aisle</button>
        <button type="button" data-cam="plan">Plan</button>
        <button type="button" data-cam="reset">Reset</button>
      </div>
    </div>
    <aside class="lab-inspector">
      <h2 id="labInspectorTitle">Systems</h2>
      <div id="labInspectorBody"></div>
    </aside>
    <div class="lab-stream">
      <div class="lab-stream-head">
        <span>Telemetry</span>
        <span>event bus</span>
      </div>
      <div id="labStreamList"></div>
    </div>
  </div>
  <script src="<?= App::e($jsUrl) ?>" defer></script>
</body>
</html>
