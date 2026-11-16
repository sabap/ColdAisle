<?php
/**
 * Public custom NOC wall. Caller has already checked the access token
 * and loaded $nocView. The stage is exactly 1920×1080 and scaled to the screen.
 *
 * @var array<string,mixed> $nocView
 */
declare(strict_types=1);

$tiles = is_array($nocView['tiles'] ?? null) ? $nocView['tiles'] : [];
$rotatePages = NocViewService::rotatePages($tiles);
$hasScene = false;
foreach ($tiles as $tile) {
    if (($tile['module'] ?? '') === 'scene') {
        $hasScene = true;
        break;
    }
}
$pageLabels = [
    'overview' => 'Overview',
    'power' => 'Power',
    'zones' => 'Zones',
    'cooling' => 'Cooling',
    'firewalls' => 'Firewalls',
];
$firstPage = '';
foreach ($rotatePages as $page) {
    if ($page !== 'firewalls') {
        $firstPage = $page;
        break;
    }
}
if ($firstPage === '' && $rotatePages !== []) {
    $firstPage = $rotatePages[0];
}
$viewName = (string)($nocView['name'] ?? 'NOC');
$cssUrl = App::url('assets/css/noc.css') . '?v=20';
$jsUrl = App::url('assets/js/noc.js') . '?v=31';
$speckUrl = App::url('assets/js/firewall-specks.js') . '?v=1';
$threeUrl = 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js';
$dcim3dUrl = App::url('assets/js/dcim-3d.js') . '?v=41';
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
    window.ColdAisleNoc = {
      apiUrl: <?= json_encode($apiUrl, JSON_UNESCAPED_SLASHES) ?>,
      token: <?= json_encode($gotToken, JSON_UNESCAPED_SLASHES) ?>,
      appVersion: <?= json_encode(App::VERSION, JSON_UNESCAPED_SLASHES) ?>,
      pollMs: 20000,
      panelRotateMs: <?= (int)($nocPanelSec * 1000) ?>,
      sceneReloadMs: 300000,
      showLabels: <?= $nocShowLabels ? 'true' : 'false' ?>,
      showRaceways: <?= $nocShowRaceways ? 'true' : 'false' ?>,
      showAirflow: <?= $nocShowAirflow ? 'true' : 'false' ?>,
      showSensors: <?= $nocShowSensors ? 'true' : 'false' ?>,
      particleDensityPct: <?= (int)$nocParticleDensity ?>,
      autoRotate: <?= $nocAutoRotate ? 'true' : 'false' ?>,
      clearedAlertTtlSec: <?= (int)$nocClearedTtl ?>,
      camTiltPct: <?= (int)$nocCamTiltPct ?>,
      camZoomPct: <?= (int)$nocCamZoomPct ?>,
      threeUrl: <?= json_encode($threeUrl, JSON_UNESCAPED_SLASHES) ?>,
      dcim3dUrl: <?= json_encode($dcim3dUrl, JSON_UNESCAPED_SLASHES) ?>,
      layout: { rotatePages: <?= json_encode(array_values($rotatePages), JSON_UNESCAPED_SLASHES) ?> }
    };
  </script>
</head>
<body class="noc-locked">
  <div class="noc-scale" id="nocScale">
  <div class="noc-root noc-custom" id="nocRoot">
    <header class="noc-header">
      <div>
        <h1><?= App::e($org !== '' ? $org : App::APP_NAME) ?> <span class="sub"><?= App::e($viewName) ?></span></h1>
        <div class="sub">NOC view</div>
      </div>
      <div class="noc-clock" id="nocClock">—</div>
    </header>
    <div class="noc-error" id="nocError" role="alert"></div>
    <div class="noc-board">
      <?php foreach ($tiles as $tile):
          $module = (string)$tile['module'];
          $style = 'grid-column:' . ((int)$tile['x'] + 1) . ' / span ' . (int)$tile['w']
              . ';grid-row:' . ((int)$tile['y'] + 1) . ' / span ' . (int)$tile['h'] . ';';
          ?>
        <?php if ($module === 'scene'): ?>
          <div class="noc-tile noc-tile-scene" style="<?= App::e($style) ?>">
            <div class="noc-3d-wrap">
              <div class="noc-3d-stage">
                <div class="noc-3d-label">Data center · 3D</div>
                <div class="noc-3d" id="noc3d" aria-label="3D floor overview"></div>
              </div>
            </div>
          </div>
        <?php elseif ($module === 'alerts'): ?>
          <div class="noc-tile noc-tile-alerts" style="<?= App::e($style) ?>">
            <aside class="noc-alerts-glass" id="nocAlertsGlass" aria-live="polite" aria-label="Recent alerts" hidden>
              <div class="noc-alerts-glass-head">
                <span class="noc-alerts-glass-title">Recent alerts</span>
                <span class="noc-alerts-glass-count" id="nocAlertsCount"></span>
              </div>
              <div class="noc-alerts-glass-list" id="nocAlertsList"></div>
            </aside>
          </div>
        <?php elseif ($module === 'rotate'): ?>
          <div class="noc-tile noc-tile-rotate noc-rotate-tile" style="<?= App::e($style) ?>">
            <div class="noc-panel-wrap">
              <div class="noc-tabs" role="tablist">
                <?php foreach ($rotatePages as $page):
                    $isFw = $page === 'firewalls';
                    $active = $page === $firstPage;
                    ?>
                  <button type="button" class="noc-tab<?= $active ? ' active' : '' ?>" data-panel="<?= App::e($page) ?>" role="tab"<?= $isFw ? ' hidden' : '' ?>><?= App::e($pageLabels[$page] ?? $page) ?></button>
                <?php endforeach; ?>
                <div class="noc-rotate-track" aria-hidden="true"><i class="noc-rotate-bar" id="nocRotateBar"></i></div>
              </div>
              <div class="noc-panel-body">
                <?php foreach ($rotatePages as $page):
                    $isFw = $page === 'firewalls';
                    $active = $page === $firstPage;
                    ?>
                  <section class="noc-panel<?= $active ? ' active' : '' ?>" id="panel-<?= App::e($page) ?>" data-panel="<?= App::e($page) ?>"<?= $isFw ? ' hidden' : '' ?>></section>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        <?php else: ?>
          <div class="noc-tile noc-tile-page" style="<?= App::e($style) ?>">
            <div class="noc-panel-wrap">
              <div class="noc-tabs"><span class="noc-tab active"><?= App::e($pageLabels[$module] ?? $module) ?></span></div>
              <div class="noc-panel-body">
                <section class="noc-panel active" id="panel-<?= App::e($module) ?>" data-panel="<?= App::e($module) ?>"></section>
              </div>
            </div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <footer class="noc-footer">
      <span><span class="noc-status-dot wait" id="nocDot"></span>
        <span id="nocStatus">Connecting…</span>
      </span>
      <span id="nocUpdated">Last update: —</span>
      <span id="nocPanelHint"><?= $rotatePages === [] ? 'Fixed tiles' : 'Panel auto-rotates' ?></span>
      <span id="nocAppVer"><?= App::e(App::APP_NAME) ?> v<?= App::e(App::VERSION) ?></span>
    </footer>
  </div>
  </div>
  <script>
    (function () {
      var frame = document.getElementById('nocScale');
      function fit() {
        var s = Math.min(window.innerWidth / 1920, window.innerHeight / 1080);
        if (!isFinite(s) || s <= 0) s = 1;
        frame.style.transform = 'scale(' + s + ')';
        frame.style.left = ((window.innerWidth - 1920 * s) / 2) + 'px';
        frame.style.top = ((window.innerHeight - 1080 * s) / 2) + 'px';
      }
      fit();
      window.addEventListener('resize', fit);
    })();
  </script>
  <script src="<?= App::e($speckUrl) ?>" defer></script>
  <?php if ($hasScene): ?>
  <script src="<?= App::e($threeUrl) ?>" defer></script>
  <script src="<?= App::e($dcim3dUrl) ?>" defer></script>
  <?php endif; ?>
  <script src="<?= App::e($jsUrl) ?>" defer></script>
</body>
</html>
